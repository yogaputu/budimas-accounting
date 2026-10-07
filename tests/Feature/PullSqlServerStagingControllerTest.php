<?php

namespace Tests\Feature;

use App\Accounting\LegacySqlServerStagingPullService;
use App\Models\Access\Permission;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use App\PermissionCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PullSqlServerStagingControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_ERROR = 'Sumber SQL Server belum dapat dibaca. Periksa koneksi worker dan izin baca sumber, lalu coba lagi.';

    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
        config()->set('database.connections.erp_postgres.driver', 'external_connections_forbidden_in_tests');
        config()->set('database.connections.legacy_sqlserver.driver', 'external_connections_forbidden_in_tests');
        config()->set('accounting.staging.sqlserver_worker', [
            'enabled' => true,
            'ssh_host' => 'worker.example.test',
            'ssh_port' => 2222,
            'ssh_user' => 'accounting-reader',
            'ssh_identity_file' => '/test-only/private key',
            'remote_python' => '/usr/bin/python3',
            'remote_php' => '/usr/bin/php',
            'remote_config' => '/test-only/source.php',
        ]);
    }

    public function test_guest_cannot_start_worker(): void
    {
        Process::fake([]);

        $this->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertRedirect(route('login'));

        Process::assertNothingRan();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    #[DataProvider('insufficientPermissions')]
    public function test_operator_requires_both_permissions_with_global_scope(array $permissions, string $scope): void
    {
        $entity = LegalEntity::factory()->create(['code' => 'BMM']);
        $user = $this->createUser($permissions, $scope, $entity);
        Process::fake([]);

        $this->actingAs($user)
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertForbidden();

        Process::assertNothingRan();
        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('staged_source_records', 0);
    }

    public static function insufficientPermissions(): array
    {
        return [
            'viewer only' => [[PermissionCode::SystemHealthView->value], 'global'],
            'sync without health' => [[PermissionCode::SyncManage->value], 'global'],
            'entity scoped operator' => [[PermissionCode::SyncManage->value, PermissionCode::SystemHealthView->value], 'entity'],
        ];
    }

    public function test_inactive_operator_is_logged_out_before_worker_runs(): void
    {
        $user = $this->createOperator();
        $user->update(['is_active' => false]);
        Process::fake([]);

        $this->actingAs($user)
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun Anda tidak aktif atau sesi telah berakhir.']);

        $this->assertGuest();
        Process::assertNothingRan();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_required_input_errors_use_the_sqlserver_error_bag(): void
    {
        Process::fake([]);

        $this->actingAs($this->createOperator())
            ->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull-sqlserver'), [])
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrorsIn('sqlserverPull', [
                'source_key' => 'Pilih sumber SQL Server.',
                'dataset' => 'Pilih jenis data SQL Server.',
                'limit' => 'Isi batas jumlah data yang akan ditarik.',
            ]);

        Process::assertNothingRan();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected_before_worker_runs(array $changes, string $field, string $message): void
    {
        $this->createSourceMasters();
        Process::fake([]);
        $input = [...$this->input(), ...$changes];

        $this->actingAs($this->createOperator())
            ->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull-sqlserver'), $input)
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrorsIn('sqlserverPull', [$field => $message])
            ->assertSessionHasInput($field, $input[$field]);

        Process::assertNothingRan();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'unknown source' => [['source_key' => 'bmm_other'], 'source_key', 'Sumber SQL Server tidak valid.'],
            'source command injection' => [['source_key' => 'bmm_solo; echo injected'], 'source_key', 'Sumber SQL Server tidak valid.'],
            'all datasets' => [['dataset' => 'all'], 'dataset', 'Jenis data SQL Server tidak valid.'],
            'arbitrary dataset' => [['dataset' => 'users; DROP TABLE users'], 'dataset', 'Jenis data SQL Server tidak valid.'],
            'limit zero' => [['limit' => 0], 'limit', 'Batas data harus antara 1 dan 100.'],
            'limit exceeds maximum' => [['limit' => 101], 'limit', 'Batas data harus antara 1 dan 100.'],
            'fractional limit' => [['limit' => 1.5], 'limit', 'Batas data harus berupa angka bulat antara 1 dan 100.'],
            'text limit' => [['limit' => 'unbounded'], 'limit', 'Batas data harus berupa angka bulat antara 1 dan 100.'],
        ];
    }

    #[DataProvider('inactiveMasters')]
    public function test_inactive_local_master_blocks_pull_without_creating_or_changing_master_data(string $master): void
    {
        [, $entities, $branches] = $this->createSourceMasters();
        ($master === 'company' ? $entities['BMM'] : $branches['bmm_solo'])->update(['is_active' => false]);
        Process::fake([]);

        $this->actingAs($this->createOperator())
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrorsIn('sqlserverPull', [
                'source_key' => 'Perusahaan dan cabang sumber harus tersedia dan aktif di Accounting.',
            ]);

        Process::assertNothingRan();
        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertDatabaseCount('legal_entities', 2);
        $this->assertDatabaseCount('branches', 6);
        $this->assertFalse(($master === 'company' ? $entities['BMM'] : $branches['bmm_solo'])->fresh()->is_active);
    }

    public static function inactiveMasters(): array
    {
        return ['inactive company' => ['company'], 'inactive branch' => ['branch']];
    }

    public function test_disabled_worker_creates_safe_failed_run_without_starting_process(): void
    {
        $this->createSourceMasters();
        config()->set('accounting.staging.sqlserver_worker.enabled', false);
        Process::fake([]);

        $this->actingAs($this->createOperator())
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertSessionHasErrorsIn('sqlserverPull', ['staging' => self::SOURCE_ERROR]);

        Process::assertNothingRan();
        $this->assertDatabaseHas('sync_runs', ['status' => SyncRun::STATUS_FAILED, 'error_summary' => self::SOURCE_ERROR]);
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertLockReleased('bmm_solo');
    }

    #[DataProvider('approvedSources')]
    public function test_pull_stages_only_the_selected_company_branch_and_dataset(
        string $sourceKey,
        string $entityCode,
        string $branchCode,
        string $dataset,
        string $externalType,
    ): void {
        [$source, $entities, $branches] = $this->createSourceMasters();
        $actor = $this->createOperator();
        $otherActor = User::factory()->create();
        $this->fakeSnapshot($this->snapshot($sourceKey, $entityCode, $branchCode, $dataset));

        $this->actingAs($actor)
            ->post(route('accounting.staging.pull-sqlserver'), [
                ...$this->input($sourceKey, $dataset),
                'initiated_by' => $otherActor->id,
                'entity_code' => $entityCode === 'BMM' ? 'TMP' : 'BMM',
                'branch_code' => 'UNAPPROVED',
            ])
            ->assertRedirect(route('accounting.staging.index', [
                'legal_entity_id' => $entities[$entityCode]->id,
                'data_source_id' => $source->id,
                'external_type' => $externalType,
            ]))
            ->assertSessionHas('sqlserver_pull_result', static fn (array $result): bool => $result['source_key'] === $sourceKey
                && $result['entity_code'] === $entityCode
                && $result['branch_code'] === $branchCode
                && $result['dataset'] === $dataset
                && $result['read_count'] === 1
                && $result['upsert_count'] === 1
                && $result['limit'] === 2);

        $record = StagedSourceRecord::query()->sole();
        $this->assertSame($entities[$entityCode]->id, $record->legal_entity_id);
        $this->assertSame($branches[$sourceKey]->id, $record->branch_id);
        $this->assertSame($externalType, $record->external_type);
        $this->assertSame($sourceKey.':C001', $record->external_key);
        $this->assertSame('Pelanggan uji', $record->payload['customer_name']);
        $run = SyncRun::query()->sole();
        $this->assertSame($actor->id, $run->initiated_by);
        $this->assertSame($entities[$entityCode]->id, $run->legal_entity_id);
        $this->assertSame('sqlserver:'.$sourceKey.':'.$dataset, $run->scope);
        $this->assertSame(SyncRun::STATUS_COMPLETED, $run->status);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('legal_entities', 2);
        $this->assertDatabaseCount('branches', 6);
        $this->assertWorkerRan($sourceKey, $dataset, 2);
        $this->assertLockReleased($sourceKey);
    }

    public static function approvedSources(): array
    {
        return [
            'BMM Solo customers' => ['bmm_solo', 'BMM', 'SLO', 'customers', 'legacy.customer'],
            'TMP Solo invoices' => ['tmp_solo', 'TMP', 'SLO', 'sales_invoices', 'legacy.sales_invoice'],
            'BMM Klaten payments' => ['bmm_klaten', 'BMM', 'KLT', 'payments', 'legacy.invoice_payment'],
            'TMP Klaten returns' => ['tmp_klaten', 'TMP', 'KLT', 'returns', 'legacy.return_request'],
            'BMM Wonogiri customers' => ['bmm_wonogiri', 'BMM', 'WNG', 'customers', 'legacy.customer'],
            'TMP Wonogiri invoices' => ['tmp_wonogiri', 'TMP', 'WNG', 'sales_invoices', 'legacy.sales_invoice'],
        ];
    }

    public function test_repeated_pull_updates_snapshot_without_colliding_with_other_branches_or_companies(): void
    {
        $this->createSourceMasters();
        $actor = $this->createOperator();
        Process::fake(["'ssh' *" => Process::sequence()
            ->push(json_encode($this->snapshot(), JSON_THROW_ON_ERROR))
            ->push(json_encode($this->snapshot('bmm_klaten', 'BMM', 'KLT'), JSON_THROW_ON_ERROR))
            ->push(json_encode($this->snapshot('tmp_solo', 'TMP', 'SLO'), JSON_THROW_ON_ERROR))
            ->push(json_encode($this->snapshot(records: [$this->record('C001', 'Nama diperbarui')]), JSON_THROW_ON_ERROR)),
        ]);
        $service = app(LegacySqlServerStagingPullService::class);
        $service->pull('bmm_solo', 'customers', 2, $actor);
        $original = StagedSourceRecord::query()->sole();
        $service->pull('bmm_klaten', 'customers', 2, $actor);
        $service->pull('tmp_solo', 'customers', 2, $actor);
        $service->pull('bmm_solo', 'customers', 2, $actor);

        $this->assertDatabaseCount('staged_source_records', 3);
        $this->assertDatabaseCount('sync_runs', 4);
        $this->assertSame('Nama diperbarui', $original->fresh()->payload['customer_name']);
        $this->assertSame('Pelanggan uji', StagedSourceRecord::query()->where('external_key', 'bmm_klaten:C001')->sole()->payload['customer_name']);
        $this->assertSame('Pelanggan uji', StagedSourceRecord::query()->where('external_key', 'tmp_solo:C001')->sole()->payload['customer_name']);
        $this->assertSame(3, StagedSourceRecord::query()->distinct()->count('branch_id'));
        $this->assertDatabaseCount('journal_entries', 0);
        Process::assertRanTimes(static fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'ssh', 4);
    }

    public function test_empty_snapshot_succeeds_and_redirects_without_empty_type_filter(): void
    {
        [$source, $entities] = $this->createSourceMasters();
        $this->fakeSnapshot($this->snapshot(records: []));
        $response = $this->actingAs($this->createOperator())
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertRedirect(route('accounting.staging.index', [
                'legal_entity_id' => $entities['BMM']->id,
                'data_source_id' => $source->id,
            ]))
            ->assertSessionHas('sqlserver_pull_result', static fn (array $result): bool => $result['read_count'] === 0 && $result['upsert_count'] === 0);

        $this->assertDatabaseHas('sync_runs', ['status' => SyncRun::STATUS_COMPLETED, 'read_count' => 0, 'upsert_count' => 0]);
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->withoutVite()->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Penarikan data SQL Server selesai.')
            ->assertSee('BMM · Solo');
        $this->assertWorkerRan('bmm_solo', 'customers', 2);
        $this->assertLockReleased('bmm_solo');
    }

    #[DataProvider('invalidSnapshots')]
    public function test_untrusted_worker_output_creates_safe_failed_run_without_staging(array $changes): void
    {
        $this->createSourceMasters();
        $actor = $this->createOperator();
        $this->fakeSnapshot([...$this->snapshot(), ...$changes]);

        $this->actingAs($actor)
            ->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrorsIn('sqlserverPull', ['staging' => self::SOURCE_ERROR]);

        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertDatabaseHas('sync_runs', [
            'status' => SyncRun::STATUS_FAILED,
            'initiated_by' => $actor->id,
            'read_count' => 0,
            'upsert_count' => 0,
            'error_count' => 1,
            'error_summary' => self::SOURCE_ERROR,
        ]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertWorkerRan('bmm_solo', 'customers', 2);
        $this->assertLockReleased('bmm_solo');
    }

    public static function invalidSnapshots(): array
    {
        $record = ['external_key' => 'C001', 'occurred_at' => null, 'payload' => ['customer_code' => 'C001']];

        return [
            'different source' => [['source_key' => 'tmp_solo']],
            'different company' => [['entity_code' => 'TMP']],
            'different branch' => [['branch_code' => 'KLT']],
            'different dataset' => [['dataset' => 'returns']],
            'oversized snapshot' => [['records' => [$record, [...$record, 'external_key' => 'C002'], [...$record, 'external_key' => 'C003']]]],
            'duplicate identity' => [['records' => [$record, $record]]],
            'blank identity' => [['records' => [[...$record, 'external_key' => '  ']]]],
            'control character identity' => [['records' => [[...$record, 'external_key' => "C001\nsecret"]]]],
            'oversized identity' => [['records' => [[...$record, 'external_key' => str_repeat('x', 128)]]]],
            'invalid payload list' => [['records' => [[...$record, 'payload' => ['private-dsn', 'password']]]]],
            'invalid date' => [['records' => [[...$record, 'occurred_at' => 'not-a-date']]]],
        ];
    }

    public function test_malformed_worker_json_is_redacted_and_audited_as_failed(): void
    {
        $this->createSourceMasters();
        Process::fake(["'ssh' *" => Process::result(output: 'invalid-json: password=never-expose-json-secret')]);

        $this->actingAs($this->createOperator())
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertSessionHasErrorsIn('sqlserverPull', ['staging' => self::SOURCE_ERROR]);

        $this->assertSame(self::SOURCE_ERROR, SyncRun::query()->sole()->error_summary);
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertStringNotContainsString('never-expose-json-secret', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertWorkerRan('bmm_solo', 'customers', 2);
        $this->assertLockReleased('bmm_solo');
    }

    public function test_failed_worker_preserves_snapshot_redacts_secrets_and_releases_lock_for_retry(): void
    {
        $this->createSourceMasters();
        $actor = $this->createOperator();
        $secret = 'sqlsrv:Server=private.internal;UID=sa;PWD=never-expose-worker-password';
        Process::fake(["'ssh' *" => Process::sequence()
            ->push(json_encode($this->snapshot(), JSON_THROW_ON_ERROR))
            ->push(Process::result(output: $secret, errorOutput: $secret, exitCode: 1))
            ->push(json_encode($this->snapshot(records: [$this->record('C001', 'Setelah retry')]), JSON_THROW_ON_ERROR)),
        ]);
        $this->actingAs($actor)->post(route('accounting.staging.pull-sqlserver'), $this->input())->assertSessionHasNoErrors();
        $record = StagedSourceRecord::query()->sole();

        $this->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull-sqlserver'), $this->input())
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrorsIn('sqlserverPull', ['staging' => self::SOURCE_ERROR]);

        $this->assertSame('Pelanggan uji', $record->fresh()->payload['customer_name']);
        $failed = SyncRun::query()->where('status', SyncRun::STATUS_FAILED)->sole();
        $this->assertSame(self::SOURCE_ERROR, $failed->error_summary);
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, $failed->toJson());
        $this->assertLockReleased('bmm_solo');
        $this->post(route('accounting.staging.pull-sqlserver'), $this->input())->assertSessionHas('sqlserver_pull_result');
        $this->assertSame('Setelah retry', $record->fresh()->payload['customer_name']);
        $this->assertDatabaseCount('staged_source_records', 1);
        $this->assertDatabaseCount('sync_runs', 3);
        Process::assertRanTimes(static fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'ssh', 3);
    }

    public function test_busy_source_lock_blocks_worker_and_creates_no_run(): void
    {
        $this->createSourceMasters();
        Process::fake([]);
        $lock = Cache::lock('accounting:staging:sqlserver:bmm_solo', 120);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->createOperator())
                ->from(route('accounting.staging.index'))
                ->post(route('accounting.staging.pull-sqlserver'), $this->input())
                ->assertRedirect(route('accounting.staging.index'))
                ->assertSessionHasErrorsIn('sqlserverPull', ['staging']);

            Process::assertNothingRan();
            $this->assertDatabaseCount('sync_runs', 0);
            $this->assertFalse(Cache::lock('accounting:staging:sqlserver:bmm_solo', 120)->get());
        } finally {
            $lock->release();
        }
    }

    public function test_fourth_pull_is_throttled_without_running_worker(): void
    {
        $this->createSourceMasters();
        $this->fakeSnapshot($this->snapshot(records: []));
        $this->actingAs($this->createOperator());

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('accounting.staging.pull-sqlserver'), $this->input())->assertRedirect();
        }
        $this->post(route('accounting.staging.pull-sqlserver'), $this->input())->assertTooManyRequests();

        $this->assertDatabaseCount('sync_runs', 3);
        Process::assertRanTimes(static fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'ssh', 3);
    }

    public function test_global_operator_sees_sqlserver_sources_and_dataset_labels_without_running_worker(): void
    {
        $this->createSourceMasters();
        Process::fake([]);

        $response = $this->withoutVite()->actingAs($this->createOperator())
            ->get(route('accounting.staging.index'))
            ->assertOk()
            ->assertSee('Tarik Data SQL Server')
            ->assertSee('action="'.route('accounting.staging.pull-sqlserver').'"', false)
            ->assertSee('name="source_key"', false)
            ->assertSee('Kwitansi Pembayaran')
            ->assertSee('Retur Penjualan')
            ->assertSee('1–100 rekaman per penarikan.');

        foreach (['BMM · Solo', 'TMP · Solo', 'BMM · Klaten', 'TMP · Klaten', 'BMM · Wonogiri', 'TMP · Wonogiri'] as $label) {
            $response->assertSee($label);
        }
        $response->assertDontSee('/test-only/private key')->assertDontSee('worker.example.test');
        Process::assertNothingRan();
    }

    public function test_scoped_reviewer_cannot_see_sqlserver_pull_form_or_other_company_snapshot(): void
    {
        [, $entities] = $this->createSourceMasters();
        $actor = $this->createUser([PermissionCode::SystemHealthView->value], 'entity', $entities['BMM']);
        Process::fake(["'ssh' *" => Process::sequence()
            ->push(json_encode($this->snapshot(records: [$this->record('C001', 'BMM visible customer')]), JSON_THROW_ON_ERROR))
            ->push(json_encode($this->snapshot('tmp_solo', 'TMP', 'SLO', records: [$this->record('C001', 'TMP private customer')]), JSON_THROW_ON_ERROR)),
        ]);
        app(LegacySqlServerStagingPullService::class)->pull('bmm_solo', 'customers', 2);
        app(LegacySqlServerStagingPullService::class)->pull('tmp_solo', 'customers', 2);

        $this->withoutVite()->actingAs($actor)->get(route('accounting.staging.index'))
            ->assertOk()
            ->assertSee('BMM visible customer')
            ->assertDontSee('TMP private customer')
            ->assertSee('Akses operator integrasi diperlukan untuk menarik data SQL Server.')
            ->assertDontSee('action="'.route('accounting.staging.pull-sqlserver').'"', false);

        Process::assertRanTimes(static fn (PendingProcess $process): bool => is_array($process->command) && $process->command[0] === 'ssh', 2);
    }

    private function createOperator(): User
    {
        return $this->createUser([PermissionCode::SyncManage->value, PermissionCode::SystemHealthView->value]);
    }

    private function createUser(array $permissions, string $scope = 'global', ?LegalEntity $entity = null): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        foreach ($permissions as $code) {
            $role->permissions()->attach(Permission::factory()->create(['code' => $code]));
        }
        RoleAssignment::factory()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_key' => $scope,
            'legal_entity_id' => $scope === 'entity' ? $entity?->id : null,
        ]);

        return $user;
    }

    private function createSourceMasters(): array
    {
        $source = DataSource::factory()->create(['key' => 'legacy_sqlserver', 'kind' => 'sqlserver', 'access_mode' => 'read_only_worker']);
        $entities = [];
        $branches = [];
        foreach (['BMM', 'TMP'] as $entityCode) {
            $entities[$entityCode] = LegalEntity::factory()->create(['code' => $entityCode]);
            foreach (['solo' => 'SLO', 'klaten' => 'KLT', 'wonogiri' => 'WNG'] as $city => $branchCode) {
                $branches[strtolower($entityCode).'_'.$city] = Branch::factory()->create([
                    'legal_entity_id' => $entities[$entityCode]->id,
                    'code' => $branchCode,
                ]);
            }
        }

        return [$source, $entities, $branches];
    }

    private function input(string $sourceKey = 'bmm_solo', string $dataset = 'customers'): array
    {
        return ['source_key' => $sourceKey, 'dataset' => $dataset, 'limit' => 2];
    }

    private function record(string $key = 'C001', string $name = 'Pelanggan uji'): array
    {
        return ['external_key' => $key, 'occurred_at' => null, 'payload' => ['customer_code' => $key, 'customer_name' => $name]];
    }

    private function snapshot(
        string $sourceKey = 'bmm_solo',
        string $entityCode = 'BMM',
        string $branchCode = 'SLO',
        string $dataset = 'customers',
        ?array $records = null,
    ): array {
        return [
            'source_key' => $sourceKey,
            'entity_code' => $entityCode,
            'branch_code' => $branchCode,
            'dataset' => $dataset,
            'records' => $records ?? [$this->record()],
        ];
    }

    private function fakeSnapshot(array $snapshot): void
    {
        Process::fake(["'ssh' *" => Process::result(output: json_encode($snapshot, JSON_THROW_ON_ERROR))]);
    }

    private function assertWorkerRan(string $sourceKey, string $dataset, int $limit): void
    {
        Process::assertRan(function (PendingProcess $process) use ($sourceKey, $dataset, $limit): bool {
            $this->assertIsArray($process->command);
            $this->assertSame([
                'ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=8',
                '-p', '2222', '-i', '/test-only/private key', 'accounting-reader@worker.example.test',
                "'/usr/bin/python3' '-' '--config' '/test-only/source.php' '--php' '/usr/bin/php' '--source' '".$sourceKey."' '--dataset' '".$dataset."' '--limit' '".$limit."'",
            ], $process->command);
            $this->assertSame(45, $process->timeout);
            $this->assertSame(file_get_contents(base_path('scripts/pull-legacy-sqlserver.py')), $process->input);

            return true;
        });
    }

    private function assertLockReleased(string $sourceKey): void
    {
        $lock = Cache::lock('accounting:staging:sqlserver:'.$sourceKey, 120);
        $this->assertTrue($lock->get(), 'The request retained its SQL Server staging lock.');
        $lock->release();
    }
}
