<?php

namespace Tests\Feature\Accounting;

use App\Accounting\ErpStagingPullException;
use App\Accounting\ErpStagingPullService;
use App\Accounting\StagingBatchIngestService;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class ErpStagingPullServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stages_the_whitelisted_bmm_snapshot_in_a_read_only_source_transaction(): void
    {
        DataSource::factory()->create([
            'key' => ErpStagingPullService::SOURCE_CONNECTION,
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        [$service, $source] = $this->serviceWithSource($this->sourceRows());

        $result = $service->pull('BMM', 'all', 2);

        $this->assertSame('BMM', $result['entity']);
        $this->assertSame('all', $result['dataset']);
        $this->assertSame(2, $result['limit']);
        $this->assertSame(1, $result['branch_count']);
        $this->assertSame([
            'customers' => 1,
            'sales_invoices' => 1,
            'payments' => 1,
            'returns' => 1,
        ], $result['counts']);
        $this->assertSame(4, $result['read_count']);
        $this->assertSame([], $result['unmapped_branch_codes']);
        $this->assertSame(0, $result['unmapped_branch_count']);
        $this->assertSame(4, $result['upsert_count']);

        $this->assertDatabaseHas('legal_entities', [
            'code' => 'BMM',
            'name' => 'PT Budimas Makmur Mulia',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('branches', [
            'code' => 'SLO',
            'name' => 'Solo',
            'is_active' => 1,
        ]);
        $this->assertDatabaseCount('staged_source_records', 4);
        $this->assertDatabaseHas('staged_source_records', [
            'external_type' => 'erp.sales_invoice',
            'external_key' => '5001',
        ]);
        $this->assertDatabaseHas('sync_runs', [
            'id' => $result['sync_run_id'],
            'status' => SyncRun::STATUS_COMPLETED,
            'read_count' => 4,
            'upsert_count' => 4,
        ]);

        $invoice = StagedSourceRecord::query()
            ->where('external_type', 'erp.sales_invoice')
            ->firstOrFail();
        $this->assertSame('NPBMM-SLO-0001', $invoice->payload['invoice_number']);
        $this->assertSame('SLO', $invoice->branch?->code);

        $source->shouldHaveReceived('statement')->with('SET TRANSACTION READ ONLY')->once();
        $source->shouldHaveReceived('statement')->with("SET LOCAL statement_timeout = '15s'")->once();
        $source->shouldNotHaveReceived('insert');
        $source->shouldNotHaveReceived('update');
        $source->shouldNotHaveReceived('delete');
    }

    public function test_stages_an_erp_record_with_an_unmapped_legacy_branch_without_assigning_it_to_the_wrong_branch(): void
    {
        DataSource::factory()->create([
            'key' => ErpStagingPullService::SOURCE_CONNECTION,
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $rows = $this->sourceRows();
        $rows['sales_invoices'][0]->branch_code = 'LEGACY';
        [$service] = $this->serviceWithSource($rows, selectTimes: 2);

        $result = $service->pull('BMM', 'sales_invoices', 1);

        $this->assertSame(['LEGACY'], $result['unmapped_branch_codes']);
        $this->assertSame(1, $result['unmapped_branch_count']);
        $invoice = StagedSourceRecord::query()
            ->where('external_type', 'erp.sales_invoice')
            ->firstOrFail();
        $this->assertNull($invoice->branch_id);
        $this->assertSame('LEGACY', $invoice->payload['branch_code']);
        $this->assertSame('unmapped_source_branch', $invoice->payload['staging_branch_assignment']);
    }

    public function test_rejects_unknown_dataset_before_opening_the_source_connection(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $service = new ErpStagingPullService($database, app(StagingBatchIngestService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dataset harus all, customers, sales_invoices, payments, atau returns.');

        $service->pull('BMM', 'untrusted_query', 1);
    }

    public function test_matches_mixed_case_erp_branch_codes_without_changing_the_source_payload(): void
    {
        DataSource::factory()->create([
            'key' => ErpStagingPullService::SOURCE_CONNECTION,
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $rows = $this->sourceRows();
        $rows['master'][0]->branch_code = 'slo';
        $rows['sales_invoices'][0]->branch_code = 'SlO';
        [$service] = $this->serviceWithSource($rows, selectTimes: 2);

        $result = $service->pull('BMM', 'sales_invoices', 1);

        $this->assertSame(0, $result['unmapped_branch_count']);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseHas('sync_runs', [
            'id' => $result['sync_run_id'],
            'status' => SyncRun::STATUS_COMPLETED,
        ]);
        $invoice = StagedSourceRecord::query()->sole();
        $this->assertSame('slo', $invoice->branch?->code);
        $this->assertSame('SlO', $invoice->payload['branch_code']);
    }

    public function test_returns_a_clean_error_when_the_read_only_source_cannot_be_connected(): void
    {
        DataSource::factory()->create([
            'key' => ErpStagingPullService::SOURCE_CONNECTION,
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $source = Mockery::mock(ConnectionInterface::class);
        $source->shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('postgres://source-credential-should-not-be-exposed'));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')
            ->once()
            ->with(ErpStagingPullService::SOURCE_CONNECTION)
            ->andReturn($source);
        $service = new ErpStagingPullService($database, app(StagingBatchIngestService::class));

        try {
            $service->pull('BMM', 'customers', 1);
            $this->fail('The unavailable source was accepted.');
        } catch (ErpStagingPullException $exception) {
            $this->assertSame(
                'Sumber ERP tidak dapat dibaca. Periksa koneksi staging dan izin SELECT read-only.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('source-credential', $exception->getMessage());
        }

        $this->assertDatabaseCount('legal_entities', 0);
        $this->assertDatabaseCount('staged_source_records', 0);
        $this->assertDatabaseHas('sync_runs', [
            'legal_entity_id' => null,
            'scope' => 'erp:customers',
            'status' => SyncRun::STATUS_FAILED,
            'read_count' => 0,
            'upsert_count' => 0,
            'error_count' => 1,
            'error_summary' => 'Sumber ERP tidak dapat dibaca. Periksa koneksi staging dan izin SELECT read-only.',
        ]);
    }

    public function test_records_a_failed_source_read_without_modifying_the_previous_snapshot(): void
    {
        $this->freezeTime();
        $dataSource = DataSource::factory()->create([
            'key' => ErpStagingPullService::SOURCE_CONNECTION,
            'access_mode' => 'read_only',
            'is_active' => true,
        ]);
        $entity = LegalEntity::factory()->create(['code' => 'BMM']);
        $previousRun = SyncRun::factory()->create([
            'data_source_id' => $dataSource->id,
            'legal_entity_id' => $entity->id,
            'scope' => 'erp:customers',
            'status' => SyncRun::STATUS_COMPLETED,
            'read_count' => 1,
            'upsert_count' => 1,
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        $snapshot = StagedSourceRecord::factory()->create([
            'data_source_id' => $dataSource->id,
            'sync_run_id' => $previousRun->id,
            'legal_entity_id' => $entity->id,
            'external_type' => 'erp.customer',
            'external_key' => '101',
            'content_hash' => str_repeat('a', 64),
            'payload' => ['customer_name' => 'Existing Customer'],
            'synced_at' => now()->subDay(),
        ]);
        $previousRunAttributes = $previousRun->fresh()->toArray();
        $snapshotAttributes = $snapshot->fresh()->toArray();
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')
            ->once()
            ->with(ErpStagingPullService::SOURCE_CONNECTION)
            ->andThrow(new RuntimeException('postgres://source-credential-should-not-be-exposed'));
        $service = new ErpStagingPullService($database, app(StagingBatchIngestService::class));

        try {
            $service->pull('BMM', 'customers', 1);
            $this->fail('The unavailable source was accepted.');
        } catch (ErpStagingPullException $exception) {
            $this->assertStringNotContainsString('source-credential', $exception->getMessage());
        }

        $this->assertDatabaseCount('sync_runs', 2);
        $failedRun = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame($dataSource->id, $failedRun->data_source_id);
        $this->assertSame($entity->id, $failedRun->legal_entity_id);
        $this->assertSame('erp:customers', $failedRun->scope);
        $this->assertSame(SyncRun::STATUS_FAILED, $failedRun->status);
        $this->assertSame(1, $failedRun->error_count);
        $this->assertSame(0, $failedRun->read_count);
        $this->assertSame(0, $failedRun->upsert_count);
        $this->assertSame(now()->toDateTimeString(), $failedRun->started_at?->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $failedRun->finished_at?->toDateTimeString());
        $this->assertSame(
            'Sumber ERP tidak dapat dibaca. Periksa koneksi staging dan izin SELECT read-only.',
            $failedRun->error_summary,
        );
        $this->assertDatabaseCount('staged_source_records', 1);
        $this->assertSame($previousRunAttributes, $previousRun->fresh()->toArray());
        $this->assertSame($snapshotAttributes, $snapshot->fresh()->toArray());
    }

    /**
     * @param  array<string, list<object>>  $rows
     * @return array{0: ErpStagingPullService, 1: MockInterface}
     */
    private function serviceWithSource(array $rows, int $selectTimes = 5): array
    {
        $source = Mockery::mock(ConnectionInterface::class);
        $source->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (\Closure $callback): mixed => $callback());
        $source->shouldReceive('statement')
            ->once()
            ->with('SET TRANSACTION READ ONLY')
            ->andReturnTrue();
        $source->shouldReceive('statement')
            ->once()
            ->with("SET LOCAL statement_timeout = '15s'")
            ->andReturnTrue();
        $source->shouldReceive('select')
            ->times($selectTimes)
            ->andReturnUsing(function (string $query, array $bindings) use ($rows): array {
                $this->assertMatchesRegularExpression('/^\\s*(WITH|SELECT)\\b/i', $query);
                $this->assertStringNotContainsString('INSERT', mb_strtoupper($query));
                $this->assertStringNotContainsString('UPDATE', mb_strtoupper($query));
                $this->assertStringNotContainsString('DELETE', mb_strtoupper($query));
                $this->assertSame('BMM', $bindings['entity_code']);

                if (str_contains($query, 'accounting_staging.companies')) {
                    return $rows['master'];
                }

                if (str_contains($query, 'accounting_staging.customers')) {
                    $this->assertSame(2, $bindings['limit']);

                    return $rows['customers'];
                }

                if (str_contains($query, 'accounting_staging.sales_invoices')) {
                    $this->assertStringContainsString('ORDER BY source_invoice_id DESC', $query);

                    return $rows['sales_invoices'];
                }

                if (str_contains($query, 'accounting_staging.sales_payments')) {
                    return $rows['payments'];
                }

                if (str_contains($query, 'accounting_staging.sales_returns')) {
                    return $rows['returns'];
                }

                $this->fail('A non-whitelisted source query was attempted.');
            });
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')
            ->once()
            ->with(ErpStagingPullService::SOURCE_CONNECTION)
            ->andReturn($source);

        return [
            new ErpStagingPullService($database, app(StagingBatchIngestService::class)),
            $source,
        ];
    }

    /**
     * @return array<string, list<object>>
     */
    private function sourceRows(): array
    {
        return [
            'master' => [(object) [
                'source_company_id' => 1,
                'company_code' => 'BMM',
                'company_name' => 'PT Budimas Makmur Mulia',
                'source_branch_id' => 5,
                'branch_code' => 'SLO',
                'branch_name' => 'Solo',
            ]],
            'customers' => [(object) [
                'source_customer_id' => 101,
                'customer_code' => 'CUS-101',
                'customer_name' => 'Toko Solo',
                'source_branch_id' => 5,
                'branch_code' => 'SLO',
                'branch_name' => 'Solo',
                'source_company_id' => 1,
                'company_code' => 'BMM',
                'company_name' => 'PT Budimas Makmur Mulia',
            ]],
            'sales_invoices' => [(object) [
                'source_invoice_id' => 5001,
                'invoice_number' => 'NPBMM-SLO-0001',
                'id_sales_order' => 7001,
                'id_order_batch' => null,
                'source_sales_order_id' => 7001,
                'sales_order_number' => 'SO-0001',
                'invoice_date' => '2026-09-24',
                'due_date' => '2026-10-24',
                'status_faktur' => 1,
                'total_penjualan' => 100000,
                'total_dana_diterima' => 0,
                'nominal_retur' => 0,
                'credit_note_nominal' => 0,
                'source_customer_id' => 101,
                'customer_code' => 'CUS-101',
                'customer_name' => 'Toko Solo',
                'source_branch_id' => 5,
                'branch_code' => 'SLO',
                'branch_name' => 'Solo',
                'source_company_id' => 1,
                'company_code' => 'BMM',
                'company_name' => 'PT Budimas Makmur Mulia',
                'occurred_at' => '2026-09-24',
            ]],
            'payments' => [(object) [
                'source_payment_id' => 9001,
                'source_invoice_id' => 5001,
                'source_sales_order_id' => 7001,
                'id_order_batch' => null,
                'payment_amount' => 100000,
                'payment_type' => 1,
                'payment_date' => '2026-09-24',
                'is_rekap' => 0,
                'client_payment_id' => 'MOB-9001',
                'invoice_number' => 'NPBMM-SLO-0001',
                'sales_order_number' => 'SO-0001',
                'source_customer_id' => 101,
                'customer_code' => 'CUS-101',
                'customer_name' => 'Toko Solo',
                'source_branch_id' => 5,
                'branch_code' => 'SLO',
                'branch_name' => 'Solo',
                'source_company_id' => 1,
                'company_code' => 'BMM',
                'company_name' => 'PT Budimas Makmur Mulia',
                'occurred_at' => '2026-09-24',
            ]],
            'returns' => [(object) [
                'source_return_id' => 3001,
                'return_number' => 'RT-001',
                'source_sales_order_id' => 7001,
                'requested_date' => '2026-09-24',
                'returned_date' => null,
                'status_request' => '1',
                'warehouse_status' => null,
                'credit_note_number' => null,
                'total_retur' => 10000,
                'total_dpp_retur' => 10000,
                'total_diskon_retur' => 0,
                'total_ppn_retur' => 0,
                'sales_order_number' => 'SO-0001',
                'source_customer_id' => 101,
                'customer_code' => 'CUS-101',
                'customer_name' => 'Toko Solo',
                'source_branch_id' => 5,
                'branch_code' => 'SLO',
                'branch_name' => 'Solo',
                'source_company_id' => 1,
                'company_code' => 'BMM',
                'company_name' => 'PT Budimas Makmur Mulia',
                'occurred_at' => '2026-09-24',
            ]],
        ];
    }
}
