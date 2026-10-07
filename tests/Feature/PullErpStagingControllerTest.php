<?php

namespace Tests\Feature;

use App\Accounting\ErpStagingPullService;
use App\Accounting\StagingBatchIngestService;
use App\Models\Access\Permission;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use App\PermissionCode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PullErpStagingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_without_reading_erp(): void
    {
        $this->preventSourceRead();

        $this->post(route('accounting.staging.pull'), $this->validInput())
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('sync_runs', 0);
    }

    #[DataProvider('insufficientPermissions')]
    public function test_non_global_operator_is_forbidden_without_reading_erp(array $permissions, string $scope): void
    {
        $entity = LegalEntity::factory()->create(['code' => 'BMM']);
        $user = $this->createUserWithPermissions($permissions, $scope, $entity);
        $this->preventSourceRead();

        $this->actingAs($user)
            ->post(route('accounting.staging.pull'), $this->validInput())
            ->assertForbidden();

        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('staged_source_records', 0);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function insufficientPermissions(): array
    {
        return [
            'no integration permission' => [[], 'global'],
            'read only reviewer' => [[PermissionCode::SystemHealthView->value], 'global'],
            'sync without health visibility' => [[PermissionCode::SyncManage->value], 'global'],
            'both permissions limited to one entity' => [[PermissionCode::SyncManage->value, PermissionCode::SystemHealthView->value], 'entity'],
        ];
    }

    public function test_inactive_operator_is_logged_out_without_reading_erp(): void
    {
        $user = $this->createOperator();
        $user->update(['is_active' => false]);
        $this->preventSourceRead();

        $this->actingAs($user)
            ->post(route('accounting.staging.pull'), $this->validInput())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun Anda tidak aktif atau sesi telah berakhir.']);

        $this->assertGuest();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_missing_fields_return_validation_errors_without_reading_erp(): void
    {
        $user = $this->createOperator();
        $this->preventSourceRead();

        $this->actingAs($user)
            ->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull'), [])
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrors([
                'entity_code' => 'Pilih perusahaan yang akan ditarik datanya.',
                'dataset' => 'Pilih jenis data ERP.',
                'limit' => 'Isi batas jumlah data yang akan ditarik.',
            ]);

        $this->assertDatabaseCount('sync_runs', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_returns_to_staging_without_reading_erp(array $changes, string $field, string $message): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'BMM']);
        $this->preventSourceRead();
        $input = [...$this->validInput(), ...$changes];

        $this->actingAs($user)
            ->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull'), $input)
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrors([$field => $message])
            ->assertSessionHasInput($field, $input[$field]);

        $this->assertDatabaseCount('sync_runs', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'unknown entity' => [['entity_code' => 'BMD'], 'entity_code', 'Perusahaan harus BMM atau TMP.'],
            'entity absent locally' => [['entity_code' => 'TMP'], 'entity_code', 'Perusahaan tidak ditemukan atau tidak aktif.'],
            'all datasets disallowed on web' => [['dataset' => 'all'], 'dataset', 'Jenis data ERP tidak valid.'],
            'arbitrary dataset' => [['dataset' => 'users; DROP TABLE users'], 'dataset', 'Jenis data ERP tidak valid.'],
            'zero limit' => [['limit' => 0], 'limit', 'Batas data harus antara 1 dan 100.'],
            'over maximum limit' => [['limit' => 101], 'limit', 'Batas data harus antara 1 dan 100.'],
            'fractional limit' => [['limit' => 1.5], 'limit', 'Batas data harus berupa angka bulat antara 1 dan 100.'],
            'text limit' => [['limit' => 'unbounded'], 'limit', 'Batas data harus berupa angka bulat antara 1 dan 100.'],
        ];
    }

    public function test_inactive_local_entity_is_rejected_before_reading_erp(): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'BMM', 'is_active' => false]);
        $this->preventSourceRead();

        $this->actingAs($user)
            ->from(route('accounting.staging.index'))
            ->post(route('accounting.staging.pull'), $this->validInput())
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrors(['entity_code' => 'Perusahaan tidak ditemukan atau tidak aktif.']);

        $this->assertDatabaseCount('sync_runs', 0);
    }

    #[DataProvider('allowedDatasets')]
    public function test_operator_stages_selected_dataset_with_actor_and_filters_without_creating_journals(
        string $entityCode,
        string $dataset,
        string $externalType,
        int $limit,
    ): void {
        $user = $this->createOperator();
        $entity = LegalEntity::factory()->create(['code' => $entityCode]);
        $dataSource = $this->createSource();
        $source = $this->bindSourceRows($entityCode, $dataset, $limit);
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->post(route('accounting.staging.pull'), [
            'entity_code' => $entityCode,
            'dataset' => $dataset,
            'limit' => $limit,
            'initiated_by' => $otherUser->id,
            'source_key' => 'legacy_sqlserver',
        ]);

        $response->assertRedirect(route('accounting.staging.index', [
            'legal_entity_id' => $entity->id,
            'data_source_id' => $dataSource->id,
            'external_type' => $externalType,
        ]))->assertSessionHasNoErrors()
            ->assertSessionHas('staging_pull_result', static fn (array $result): bool => $result['entity'] === $entityCode
                && $result['dataset'] === $dataset
                && $result['limit'] === $limit
                && $result['read_count'] === 1
                && $result['upsert_count'] === 1
                && $result['counts'] === [$dataset => 1]);
        $this->assertDatabaseHas('sync_runs', [
            'data_source_id' => $dataSource->id,
            'legal_entity_id' => $entity->id,
            'initiated_by' => $user->id,
            'scope' => 'erp:'.$dataset,
            'status' => SyncRun::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('staged_source_records', [
            'legal_entity_id' => $entity->id,
            'data_source_id' => $dataSource->id,
            'external_type' => $externalType,
            'external_key' => '101',
        ]);
        $this->assertDatabaseCount('staged_source_records', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $source->shouldNotHaveReceived('insert');
        $source->shouldNotHaveReceived('update');
        $source->shouldNotHaveReceived('delete');
        $this->assertLockReleased($entityCode);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function allowedDatasets(): array
    {
        return [
            'BMM customers minimum batch' => ['BMM', 'customers', 'erp.customer', 1],
            'TMP invoices maximum batch' => ['TMP', 'sales_invoices', 'erp.sales_invoice', 100],
            'BMM mobile payment claims' => ['BMM', 'payments', 'erp.mobile_payment_claim', 20],
            'TMP returns' => ['TMP', 'returns', 'erp.return_request', 20],
        ];
    }

    public function test_repeating_a_successful_pull_updates_the_same_snapshot(): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'BMM']);
        $this->createSource();
        $this->bindSourceRows('BMM', 'customers', 10);
        $this->actingAs($user)->post(route('accounting.staging.pull'), $this->validInput())
            ->assertSessionHasNoErrors();
        $originalId = StagedSourceRecord::query()->sole()->id;
        $this->bindSourceRows('BMM', 'customers', 10, ['customer_name' => 'Updated customer']);

        $this->post(route('accounting.staging.pull'), $this->validInput())
            ->assertSessionHasNoErrors();

        $record = StagedSourceRecord::query()->sole();
        $this->assertSame($originalId, $record->id);
        $this->assertSame('Updated customer', $record->payload['customer_name']);
        $this->assertDatabaseCount('sync_runs', 2);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_empty_snapshot_redirect_omits_a_missing_type_filter_and_can_be_opened(): void
    {
        $user = $this->createOperator();
        $entity = LegalEntity::factory()->create(['code' => 'BMM']);
        $source = $this->createSource();
        $this->bindSourceRows('BMM', 'customers', 10, empty: true);

        $response = $this->actingAs($user)->post(route('accounting.staging.pull'), $this->validInput());

        $response->assertRedirect(route('accounting.staging.index', [
            'legal_entity_id' => $entity->id,
            'data_source_id' => $source->id,
        ]))->assertSessionHasNoErrors()
            ->assertSessionHas('staging_pull_result.read_count', 0)
            ->assertSessionHas('staging_pull_result.upsert_count', 0);
        $this->withoutVite()->get($response->headers->get('Location'))->assertOk();
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_source_failure_records_safe_actor_audit_preserves_snapshot_and_releases_lock_for_retry(): void
    {
        $user = $this->createOperator();
        $entity = LegalEntity::factory()->create(['code' => 'BMM']);
        $source = $this->createSource();
        $previous = StagedSourceRecord::factory()->create([
            'data_source_id' => $source->id, 'legal_entity_id' => $entity->id,
            'external_type' => 'erp.customer', 'external_key' => '101',
            'payload' => ['customer_name' => 'Previous snapshot'],
            'content_hash' => str_repeat('a', 64), 'synced_at' => now(),
        ]);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with('erp_postgres')
            ->andThrow(new RuntimeException('postgres://credential-must-not-be-exposed'));
        $this->bindService($database);

        $this->actingAs($user)->post(route('accounting.staging.pull'), $this->validInput())
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrors('staging')
            ->assertSessionHasInput('entity_code', 'BMM')
            ->assertSessionHasInput('dataset', 'customers')
            ->assertSessionHasInput('limit', 10);

        $run = SyncRun::query()->sole();
        $this->assertSame($user->id, $run->initiated_by);
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertStringNotContainsString('credential-must-not-be-exposed', $run->error_summary);
        $this->assertStringNotContainsString('credential-must-not-be-exposed', session('errors')->first('staging'));
        $this->assertSame(['customer_name' => 'Previous snapshot'], $previous->fresh()->payload);
        $this->assertLockReleased('BMM');
        $this->bindSourceRows('BMM', 'customers', 10);

        $this->post(route('accounting.staging.pull'), $this->validInput())
            ->assertSessionHas('staging_pull_result');

        $this->assertDatabaseCount('staged_source_records', 1);
        $this->assertDatabaseCount('sync_runs', 2);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_unexpected_invalid_source_date_is_redacted_and_unlocks_the_entity(): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'BMM']);
        $this->createSource();
        $this->bindSourceRows('BMM', 'customers', 10, ['occurred_at' => 'credential-must-not-be-exposed']);

        $this->actingAs($user)->post(route('accounting.staging.pull'), $this->validInput())
            ->assertRedirect(route('accounting.staging.index'))
            ->assertSessionHasErrors('staging')
            ->assertSessionHasInput('dataset', 'customers');

        $this->assertStringNotContainsString('credential-must-not-be-exposed', session('errors')->first('staging'));
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertLockReleased('BMM');
    }

    public function test_busy_entity_lock_prevents_source_read_and_keeps_existing_lock(): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'BMM']);
        $this->preventSourceRead();
        $lock = Cache::lock('accounting:staging:erp:BMM', 120);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($user)->post(route('accounting.staging.pull'), $this->validInput())
                ->assertRedirect(route('accounting.staging.index'))
                ->assertSessionHasErrors('staging')
                ->assertSessionHasInput('entity_code', 'BMM');

            $this->assertDatabaseCount('sync_runs', 0);
            $this->assertDatabaseCount('staged_source_records', 0);
            $this->assertFalse(Cache::lock('accounting:staging:erp:BMM', 120)->get());
        } finally {
            $lock->release();
        }
    }

    public function test_lock_for_another_entity_does_not_block_the_selected_company(): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'TMP']);
        $this->createSource();
        $this->bindSourceRows('TMP', 'customers', 10);
        $lock = Cache::lock('accounting:staging:erp:BMM', 120);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($user)->post(route('accounting.staging.pull'), $this->validInput('TMP'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('staging_pull_result');
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('staged_source_records', 1);
    }

    public function test_fourth_attempt_is_throttled_before_source_read(): void
    {
        $user = $this->createOperator();
        LegalEntity::factory()->create(['code' => 'BMM']);
        $this->preventSourceRead();
        $lock = Cache::lock('accounting:staging:erp:BMM', 120);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($user);
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this->post(route('accounting.staging.pull'), $this->validInput())
                    ->assertSessionHasErrors('staging');
            }

            $this->post(route('accounting.staging.pull'), $this->validInput())
                ->assertTooManyRequests();
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('sync_runs', 0);
    }

    private function createOperator(): User
    {
        return $this->createUserWithPermissions([
            PermissionCode::SyncManage->value, PermissionCode::SystemHealthView->value,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createUserWithPermissions(array $permissions, string $scope = 'global', ?LegalEntity $entity = null): User
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

    private function createSource(): DataSource
    {
        return DataSource::factory()->create(['key' => 'erp_postgres', 'access_mode' => 'read_only']);
    }

    /**
     * @return array{entity_code: string, dataset: string, limit: int}
     */
    private function validInput(string $entityCode = 'BMM'): array
    {
        return ['entity_code' => $entityCode, 'dataset' => 'customers', 'limit' => 10];
    }

    private function preventSourceRead(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $this->bindService($database);
    }

    private function bindService(DatabaseManager $database): void
    {
        $this->app->instance(ErpStagingPullService::class, new ErpStagingPullService(
            $database,
            app(StagingBatchIngestService::class),
        ));
        $this->app['router']->getRoutes()->getByName('accounting.staging.pull')?->flushController();
    }

    /**
     * @param  array<string, mixed>  $recordChanges
     */
    private function bindSourceRows(string $entityCode, string $dataset, int $limit, array $recordChanges = [], bool $empty = false): MockInterface
    {
        $master = (object) [
            'source_company_id' => 1, 'company_code' => $entityCode, 'company_name' => 'PT '.$entityCode,
            'source_branch_id' => 5, 'branch_code' => 'SLO', 'branch_name' => 'Solo',
        ];
        $idColumn = match ($dataset) {
            'customers' => 'source_customer_id',
            'sales_invoices' => 'source_invoice_id',
            'payments' => 'source_payment_id',
            'returns' => 'source_return_id',
        };
        $sourceView = match ($dataset) {
            'customers' => 'accounting_staging.customers',
            'sales_invoices' => 'accounting_staging.sales_invoices',
            'payments' => 'accounting_staging.sales_payments',
            'returns' => 'accounting_staging.sales_returns',
        };
        $record = (object) [(string) $idColumn => 101, 'branch_code' => 'SLO',
            'customer_name' => 'Source customer', 'occurred_at' => '2026-09-28', ...$recordChanges];
        $source = Mockery::mock(ConnectionInterface::class);
        $source->shouldReceive('transaction')->once()
            ->andReturnUsing(static fn (\Closure $callback): mixed => $callback());
        $source->shouldReceive('statement')->once()->with('SET TRANSACTION READ ONLY')->andReturnTrue();
        $source->shouldReceive('statement')->once()->with("SET LOCAL statement_timeout = '15s'")->andReturnTrue();
        $source->shouldReceive('select')->twice()
            ->andReturnUsing(function (string $sql, array $bindings) use ($master, $record, $sourceView, $entityCode, $limit, $empty): array {
                $this->assertSame($entityCode, $bindings['entity_code']);
                $this->assertMatchesRegularExpression('/^\s*(WITH|SELECT)\b/i', $sql);
                if (str_contains($sql, 'accounting_staging.companies')) {
                    return [$master];
                }
                $this->assertStringContainsString($sourceView, $sql);
                $this->assertSame($limit, $bindings['limit']);

                return $empty ? [] : [$record];
            });
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->with('erp_postgres')->andReturn($source);
        $this->bindService($database);

        return $source;
    }

    private function assertLockReleased(string $entityCode): void
    {
        $lock = Cache::lock('accounting:staging:erp:'.$entityCode, 120);
        $this->assertTrue($lock->get(), 'The completed request retained its ERP staging lock.');
        $lock->release();
    }
}
