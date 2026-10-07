<?php

namespace Tests\Feature;

use App\Models\Access\Permission;
use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use App\PermissionCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StagingDataControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_staging_viewer(): void
    {
        $this->get(route('accounting.staging.index'))
            ->assertRedirect(route('login'));
    }

    public function test_fresh_schema_creates_snapshot_references_after_staging_tables(): void
    {
        $this->assertTrue(Schema::hasColumn('sync_runs', 'initiated_by'));
        $this->assertTrue(Schema::hasColumn('staged_source_records', 'sync_run_id'));
        $this->assertTrue(Schema::hasIndex('staged_source_records', 'staged_records_sync_run_type_idx'));
        $this->assertTrue(Schema::hasIndex('staged_source_records', 'staged_records_source_type_synced_idx'));
    }

    public function test_viewer_can_open_the_read_only_staging_viewer(): void
    {
        $this->seed();
        $viewer = $this->createUserWithSeededRole('viewer');

        $this->withoutVite()
            ->actingAs($viewer)
            ->get(route('accounting.staging.index'))
            ->assertOk()
            ->assertSee('Data Staging')
            ->assertSee('Akses operator integrasi diperlukan')
            ->assertDontSee('php artisan')
            ->assertDontSee('action="'.route('accounting.staging.pull').'"', false);
    }

    public function test_authorized_user_sees_local_snapshot_records_and_erp_pull_form(): void
    {
        $this->seed();
        $admin = $this->createUserWithSeededRole('super_admin');
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $source = DataSource::query()->where('key', 'erp_postgres')->firstOrFail();
        $run = $this->createCompletedRun($source, $bmm, $admin);

        StagedSourceRecord::query()->create([
            'data_source_id' => $source->id,
            'sync_run_id' => $run->id,
            'legal_entity_id' => $bmm->id,
            'external_type' => 'erp.sales_invoice',
            'external_key' => 'INV-BMM-0001',
            'external_updated_at' => '2026-09-25 08:00:00',
            'content_hash' => str_repeat('a', 64),
            'payload' => [
                'invoice_number' => 'INV-BMM-0001',
                'token' => 'source-secret-must-not-render',
            ],
            'occurred_at' => '2026-09-25 08:00:00',
            'synced_at' => now(),
        ]);

        $this->withoutVite()
            ->actingAs($admin)
            ->get(route('accounting.staging.index', [
                'legal_entity_id' => $bmm->id,
                'data_source_id' => $source->id,
            ]))
            ->assertOk()
            ->assertSee('Data Staging')
            ->assertSee('Faktur Penjualan')
            ->assertSee('INV-BMM-0001')
            ->assertSee('Tarik Data ERP')
            ->assertSee('action="'.route('accounting.staging.pull').'"', false)
            ->assertSee('name="entity_code"', false)
            ->assertSee('name="dataset"', false)
            ->assertSee('name="limit"', false)
            ->assertDontSee('php artisan')
            ->assertSee('[disamarkan]')
            ->assertDontSee('source-secret-must-not-render');
    }

    public function test_entity_scoped_user_only_sees_staged_records_in_authorized_entity(): void
    {
        $this->seed();
        $bmm = LegalEntity::query()->where('code', 'BMM')->firstOrFail();
        $tmp = LegalEntity::query()->where('code', 'TMP')->firstOrFail();
        $source = DataSource::query()->where('key', 'erp_postgres')->firstOrFail();
        $user = $this->createEntityScopedStagingUser($bmm);
        $bmmRun = $this->createCompletedRun($source, $bmm, $user);
        $tmpRun = $this->createCompletedRun($source, $tmp, $user);

        $this->createStagedRecord($source, $bmmRun, $bmm, 'INV-BMM-SCOPE');
        $this->createStagedRecord($source, $tmpRun, $tmp, 'INV-TMP-PRIVATE');

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('accounting.staging.index'))
            ->assertOk()
            ->assertSee('INV-BMM-SCOPE')
            ->assertDontSee('INV-TMP-PRIVATE')
            ->assertSee('Akses operator integrasi diperlukan')
            ->assertDontSee('php artisan')
            ->assertDontSee('action="'.route('accounting.staging.pull').'"', false)
            ->assertSee('BMM · seluruh cabang dalam scope perusahaan');
    }

    public function test_dashboard_shows_staging_navigation_to_global_integration_reviewer(): void
    {
        $this->seed();
        $admin = $this->createUserWithSeededRole('super_admin');

        $this->withoutVite()
            ->actingAs($admin)
            ->get(route('accounting.dashboard'))
            ->assertOk()
            ->assertSee('Data staging')
            ->assertSee(route('accounting.staging.index'), false);
    }

    private function createUserWithSeededRole(string $roleCode): User
    {
        $user = User::factory()->create();

        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'scope_key' => 'global',
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function createEntityScopedStagingUser(LegalEntity $entity): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create([
            'code' => 'staging_entity_reviewer',
            'name' => 'Staging Entity Reviewer',
        ]);
        $permission = Permission::query()
            ->where('code', PermissionCode::SystemHealthView->value)
            ->firstOrFail();

        $role->permissions()->attach($permission);

        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'legal_entity_id' => $entity->id,
            'scope_key' => 'entity',
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function createCompletedRun(DataSource $source, LegalEntity $entity, User $actor): SyncRun
    {
        return SyncRun::query()->create([
            'data_source_id' => $source->id,
            'legal_entity_id' => $entity->id,
            'initiated_by' => $actor->id,
            'scope' => 'sales_invoices',
            'status' => SyncRun::STATUS_COMPLETED,
            'read_count' => 1,
            'upsert_count' => 1,
            'error_count' => 0,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    private function createStagedRecord(
        DataSource $source,
        SyncRun $run,
        LegalEntity $entity,
        string $externalKey,
    ): void {
        StagedSourceRecord::query()->create([
            'data_source_id' => $source->id,
            'sync_run_id' => $run->id,
            'legal_entity_id' => $entity->id,
            'external_type' => 'erp.sales_invoice',
            'external_key' => $externalKey,
            'content_hash' => hash('sha256', $externalKey),
            'payload' => ['invoice_number' => $externalKey],
            'synced_at' => now(),
        ]);
    }
}
