<?php

namespace Tests\Feature;

use App\Accounting\StagingBatchIngestService;
use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StagingBatchIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));
    }

    public function test_the_same_source_key_is_staged_independently_for_each_legal_entity(): void
    {
        DataSource::factory()->create([
            'key' => 'erp_postgres',
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $bmm = LegalEntity::factory()->create(['code' => 'BMM']);
        $tmp = LegalEntity::factory()->create(['code' => 'TMP']);
        $service = app(StagingBatchIngestService::class);

        $service->ingest($this->batchFor('BMM', 'BMM Customer'));
        $service->ingest($this->batchFor('TMP', 'TMP Customer'));

        $this->assertDatabaseCount('staged_source_records', 2);
        $this->assertDatabaseHas('staged_source_records', [
            'legal_entity_id' => $bmm->id,
            'external_type' => 'erp.customer',
            'external_key' => '101',
        ]);
        $this->assertDatabaseHas('staged_source_records', [
            'legal_entity_id' => $tmp->id,
            'external_type' => 'erp.customer',
            'external_key' => '101',
        ]);

        $service->ingest($this->batchFor('BMM', 'BMM Customer Updated'));

        $this->assertDatabaseCount('staged_source_records', 2);
        $this->assertSame(
            'BMM Customer Updated',
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
        $this->assertTrue(Schema::hasIndex('staged_source_records', 'staged_source_record_entity_unique'));
        $this->assertFalse(Schema::hasIndex('staged_source_records', 'staged_source_record_unique'));
    }

    public function test_matches_branch_codes_case_insensitively_within_the_batch_entity(): void
    {
        DataSource::factory()->create([
            'key' => 'erp_postgres',
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $bmm = LegalEntity::factory()->create(['code' => 'BMM']);
        $tmp = LegalEntity::factory()->create(['code' => 'TMP']);
        $bmmBranch = Branch::factory()->create([
            'legal_entity_id' => $bmm->id,
            'code' => 'SLO',
        ]);
        Branch::factory()->create([
            'legal_entity_id' => $tmp->id,
            'code' => 'SLO',
        ]);
        $batch = $this->batchFor('BMM', 'BMM Customer');
        $batch['records'][0]['branch_code'] = ' slo ';

        app(StagingBatchIngestService::class)->ingest($batch);

        $this->assertDatabaseHas('staged_source_records', [
            'legal_entity_id' => $bmm->id,
            'branch_id' => $bmmBranch->id,
            'external_type' => 'erp.customer',
            'external_key' => '101',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function batchFor(string $legalEntityCode, string $customerName): array
    {
        return [
            'source_key' => 'erp_postgres',
            'legal_entity_code' => $legalEntityCode,
            'scope' => 'erp:customers',
            'records' => [[
                'external_type' => 'erp.customer',
                'external_key' => '101',
                'payload' => ['name' => $customerName],
            ]],
        ];
    }
}
