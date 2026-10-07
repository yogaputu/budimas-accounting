<?php

namespace Tests\Feature\Accounting;

use App\Accounting\StagingSnapshotService;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StagingSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_starts_a_queued_run_only_for_an_active_read_only_source(): void
    {
        $source = DataSource::factory()->create([
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $entity = LegalEntity::factory()->create([
            'is_active' => true,
        ]);

        $syncRun = app(StagingSnapshotService::class)->beginRun(
            dataSource: $source,
            legalEntity: $entity,
            scope: 'erp:customers',
        );

        $this->assertSame(SyncRun::STATUS_QUEUED, $syncRun->status);
        $this->assertSame($source->id, $syncRun->data_source_id);
        $this->assertSame($entity->id, $syncRun->legal_entity_id);
        $this->assertDatabaseHas('access_audits', [
            'action' => 'integration.sync_run_created',
            'subject_id' => $syncRun->id,
        ]);
    }

    public function test_staging_snapshot_identity_is_scoped_by_legal_entity(): void
    {
        $source = DataSource::factory()->create([
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $bmm = LegalEntity::factory()->create(['code' => 'BMM']);
        $tmp = LegalEntity::factory()->create(['code' => 'TMP']);
        $service = app(StagingSnapshotService::class);

        $bmmRun = $service->beginRun($source, $bmm, 'erp:customers');
        $tmpRun = $service->beginRun($source, $tmp, 'erp:customers');

        $service->stageBatch($bmmRun, [$this->customerRecord('BMM Customer')]);
        $service->stageBatch($tmpRun, [$this->customerRecord('TMP Customer')]);

        $this->assertDatabaseCount('staged_source_records', 2);
        $this->assertSame(
            'BMM Customer',
            StagedSourceRecord::query()
                ->where('legal_entity_id', $bmm->id)
                ->where('external_type', 'erp.customer')
                ->where('external_key', '101')
                ->sole()
                ->payload['name'],
        );
        $this->assertSame(
            'TMP Customer',
            StagedSourceRecord::query()
                ->where('legal_entity_id', $tmp->id)
                ->where('external_type', 'erp.customer')
                ->where('external_key', '101')
                ->sole()
                ->payload['name'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function customerRecord(string $customerName): array
    {
        return [
            'external_type' => 'erp.customer',
            'external_key' => '101',
            'payload' => ['name' => $customerName],
        ];
    }
}
