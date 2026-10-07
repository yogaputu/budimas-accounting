<?php

namespace App\Accounting;

use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Pulls an approved, bounded ERP snapshot into the local Accounting staging
 * area for the CLI and authorized web operators. It owns no ERP mutations:
 * all remote statements are fixed SELECT statements in a PostgreSQL
 * READ ONLY transaction.
 */
final class ErpStagingPullService
{
    public const SOURCE_CONNECTION = 'erp_postgres';

    public const DATASET_ALL = 'all';

    public const DATASET_CUSTOMERS = 'customers';

    public const DATASET_SALES_INVOICES = 'sales_invoices';

    public const DATASET_PAYMENTS = 'payments';

    public const DATASET_RETURNS = 'returns';

    /**
     * The statement timeout must remain a static value. It is intentionally
     * not configurable through the command line.
     */
    private const SOURCE_STATEMENT_TIMEOUT = "SET LOCAL statement_timeout = '15s'";

    private const SOURCE_READ_ERROR_MESSAGE = 'Sumber ERP tidak dapat dibaca. Periksa koneksi staging dan izin SELECT read-only.';

    /**
     * Static, reviewed master query. The restricted accounting_staging views
     * expose only the approved company and branch projection from ERP.
     */
    private const MASTER_QUERY = <<<'SQL'
SELECT
    c.source_company_id,
    c.company_code,
    c.company_name,
    b.source_branch_id,
    b.branch_code,
    b.branch_name
FROM accounting_staging.companies c
LEFT JOIN accounting_staging.branches b ON b.source_company_id = c.source_company_id
WHERE UPPER(TRIM(c.company_code)) = UPPER(TRIM(:entity_code))
ORDER BY b.branch_code ASC NULLS LAST, b.source_branch_id ASC
LIMIT 100
SQL;

    /**
     * @var array<string, string>
     *
     * Invoice rows intentionally come from the reviewed staging view instead
     * of joining ERP transaction tables here. The view has one row per source
     * invoice, including multi-principal invoices, so a pull cannot multiply
     * one invoice into multiple staged records.
     */
    private const DATASET_QUERIES = [
        self::DATASET_CUSTOMERS => <<<'SQL'
SELECT
    source_customer_id,
    customer_code,
    customer_name,
    source_branch_id,
    branch_code,
    branch_name,
    source_company_id,
    company_code,
    company_name
FROM accounting_staging.customers
WHERE UPPER(TRIM(company_code)) = UPPER(TRIM(:entity_code))
ORDER BY source_customer_id ASC
LIMIT :limit
SQL,
        self::DATASET_SALES_INVOICES => <<<'SQL'
SELECT
    source_invoice_id,
    invoice_number,
    id_sales_order,
    id_order_batch,
    source_sales_order_id,
    sales_order_number,
    invoice_date,
    due_date,
    status_faktur,
    total_penjualan,
    total_dana_diterima,
    nominal_retur,
    credit_note_nominal,
    source_customer_id,
    customer_code,
    customer_name,
    source_branch_id,
    branch_code,
    branch_name,
    source_company_id,
    company_code,
    company_name,
    invoice_date AS occurred_at
FROM accounting_staging.sales_invoices
WHERE UPPER(TRIM(company_code)) = UPPER(TRIM(:entity_code))
ORDER BY source_invoice_id DESC
LIMIT :limit
SQL,
        self::DATASET_PAYMENTS => <<<'SQL'
SELECT
    source_payment_id,
    source_invoice_id,
    source_sales_order_id,
    id_order_batch,
    payment_amount,
    payment_type,
    payment_date,
    is_rekap,
    client_payment_id,
    invoice_number,
    sales_order_number,
    source_customer_id,
    customer_code,
    customer_name,
    source_branch_id,
    branch_code,
    branch_name,
    source_company_id,
    company_code,
    company_name,
    payment_date AS occurred_at
FROM accounting_staging.sales_payments sc
WHERE UPPER(TRIM(sc.company_code)) = UPPER(TRIM(:entity_code))
ORDER BY sc.payment_date DESC NULLS LAST, sc.source_payment_id DESC
LIMIT :limit
SQL,
        self::DATASET_RETURNS => <<<'SQL'
SELECT
    source_return_id,
    return_number,
    source_sales_order_id,
    requested_date,
    returned_date,
    status_request,
    warehouse_status,
    credit_note_number,
    total_retur,
    total_dpp_retur,
    total_diskon_retur,
    total_ppn_retur,
    sales_order_number,
    source_customer_id,
    customer_code,
    customer_name,
    source_branch_id,
    branch_code,
    branch_name,
    source_company_id,
    company_code,
    company_name,
    COALESCE(returned_date, requested_date) AS occurred_at
FROM accounting_staging.sales_returns
WHERE UPPER(TRIM(company_code)) = UPPER(TRIM(:entity_code))
ORDER BY COALESCE(returned_date, requested_date) DESC NULLS LAST, source_return_id DESC
LIMIT :limit
SQL,
    ];

    /**
     * @var array<string, array{external_type: string, id_column: string}>
     *
     * A `setoran_customer` row is deliberately staged as a Mobile Sales
     * payment claim, not as a final Finance receipt. Final receipt matching
     * remains a separate controlled finance workflow.
     */
    private const RECORD_DEFINITIONS = [
        self::DATASET_CUSTOMERS => [
            'external_type' => 'erp.customer',
            'id_column' => 'source_customer_id',
        ],
        self::DATASET_SALES_INVOICES => [
            'external_type' => 'erp.sales_invoice',
            'id_column' => 'source_invoice_id',
        ],
        self::DATASET_PAYMENTS => [
            'external_type' => 'erp.mobile_payment_claim',
            'id_column' => 'source_payment_id',
        ],
        self::DATASET_RETURNS => [
            'external_type' => 'erp.return_request',
            'id_column' => 'source_return_id',
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly StagingBatchIngestService $stagingBatchIngestService,
    ) {}

    /**
     * Pull one bounded staging snapshot. The limit applies to each requested
     * record type when `all` is selected; the command reports the resulting
     * count per type.
     *
     * @return array{
     *     branch_count: int,
     *     counts: array<string, int>,
     *     dataset: string,
     *     entity: string,
     *     limit: int,
     *     read_count: int,
     *     sync_run_id: int,
     *     unmapped_branch_codes: list<string>,
     *     unmapped_branch_count: int,
     *     upsert_count: int
     * }
     */
    public function pull(string $entityCode, string $dataset = self::DATASET_ALL, int $limit = 100, ?User $actor = null): array
    {
        $normalizedEntityCode = $this->normalizeEntityCode($entityCode);
        $normalizedDataset = $this->normalizeDataset($dataset);
        $normalizedLimit = $this->normalizeLimit($limit);
        $dataSource = $this->approvedDataSource();
        $requestedDatasets = $normalizedDataset === self::DATASET_ALL
            ? array_keys(self::DATASET_QUERIES)
            : [$normalizedDataset];

        $startedAt = now();

        try {
            $snapshot = $this->readSourceSnapshot(
                entityCode: $normalizedEntityCode,
                datasets: $requestedDatasets,
                limit: $normalizedLimit,
            );
        } catch (ErpStagingPullException $exception) {
            SyncRun::query()->create([
                'data_source_id' => $dataSource->id,
                'legal_entity_id' => LegalEntity::query()->where('code', $normalizedEntityCode)->value('id'),
                'initiated_by' => $actor?->id,
                'scope' => 'erp:'.$normalizedDataset,
                'status' => SyncRun::STATUS_FAILED,
                'read_count' => 0,
                'upsert_count' => 0,
                'error_count' => 1,
                'error_summary' => self::SOURCE_READ_ERROR_MESSAGE,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        $master = $this->synchronizeMaster($snapshot['master'], $normalizedEntityCode);
        $staging = $this->toStagingRecords(
            recordsByDataset: $snapshot['datasets'],
            datasets: $requestedDatasets,
            allowedBranchCodes: $master['branch_codes'],
        );
        $counts = [];

        foreach ($requestedDatasets as $requestedDataset) {
            $counts[$requestedDataset] = count($snapshot['datasets'][$requestedDataset]);
        }

        $ingested = $this->stagingBatchIngestService->ingest([
            'source_key' => $dataSource->key,
            'legal_entity_code' => $master['legal_entity']->code,
            'scope' => 'erp:'.$normalizedDataset,
            'records' => $staging['records'],
        ], $actor);

        return [
            'branch_count' => $master['branch_count'],
            'counts' => $counts,
            'dataset' => $normalizedDataset,
            'entity' => $normalizedEntityCode,
            'limit' => $normalizedLimit,
            'read_count' => $ingested['read_count'],
            'sync_run_id' => (int) $ingested['sync_run']->id,
            'unmapped_branch_codes' => $staging['unmapped_branch_codes'],
            'unmapped_branch_count' => $staging['unmapped_branch_count'],
            'upsert_count' => $ingested['upsert_count'],
        ];
    }

    private function approvedDataSource(): DataSource
    {
        $dataSource = DataSource::query()
            ->where('key', self::SOURCE_CONNECTION)
            ->where('is_active', true)
            ->first();

        if ($dataSource === null || $dataSource->access_mode !== 'read_only') {
            throw new ErpStagingPullException(
                'Sumber ERP PostgreSQL belum aktif sebagai sumber read-only Accounting.',
            );
        }

        $sourceConfiguration = config('accounting.sources.'.self::SOURCE_CONNECTION);

        if (! is_array($sourceConfiguration)
            || ($sourceConfiguration['connection'] ?? null) !== self::SOURCE_CONNECTION
            || ($sourceConfiguration['mode'] ?? null) !== 'read_only') {
            throw new ErpStagingPullException(
                'Konfigurasi sumber ERP PostgreSQL belum disetujui untuk staging read-only.',
            );
        }

        return $dataSource;
    }

    /**
     * @param  list<string>  $datasets
     * @return array{master: list<array<string, mixed>>, datasets: array<string, list<array<string, mixed>>>}
     */
    private function readSourceSnapshot(string $entityCode, array $datasets, int $limit): array
    {
        try {
            /** @var ConnectionInterface $source */
            $source = $this->database->connection(self::SOURCE_CONNECTION);

            return $source->transaction(function () use ($datasets, $entityCode, $limit, $source): array {
                // PostgreSQL requires the transaction mode to be set before
                // any source query is issued.
                $source->statement('SET TRANSACTION READ ONLY');
                $source->statement(self::SOURCE_STATEMENT_TIMEOUT);

                $recordsByDataset = [];

                foreach ($datasets as $dataset) {
                    $recordsByDataset[$dataset] = $this->normalizeSourceRows($source->select(
                        self::DATASET_QUERIES[$dataset],
                        ['entity_code' => $entityCode, 'limit' => $limit],
                    ));
                }

                return [
                    'master' => $this->normalizeSourceRows($source->select(
                        self::MASTER_QUERY,
                        ['entity_code' => $entityCode],
                    )),
                    'datasets' => $recordsByDataset,
                ];
            });
        } catch (ErpStagingPullException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ErpStagingPullException(
                self::SOURCE_READ_ERROR_MESSAGE,
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $masterRows
     * @return array{branch_codes: list<string>, legal_entity: LegalEntity, branch_count: int}
     */
    private function synchronizeMaster(array $masterRows, string $entityCode): array
    {
        if ($masterRows === []) {
            throw new ErpStagingPullException("Perusahaan ERP {$entityCode} tidak ditemukan pada sumber staging.");
        }

        $companyName = $this->requiredString($masterRows[0], 'company_name', 'nama perusahaan');
        $companyCode = $this->requiredString($masterRows[0], 'company_code', 'kode perusahaan');

        if (mb_strtoupper($companyCode) !== $entityCode) {
            throw new ErpStagingPullException('Sumber staging mengembalikan perusahaan di luar scope yang dipilih.');
        }

        return DB::transaction(function () use ($companyName, $entityCode, $masterRows): array {
            $legalEntity = LegalEntity::query()->updateOrCreate(
                ['code' => $entityCode],
                ['name' => $companyName, 'is_active' => true],
            );
            $branchCount = 0;
            $branchCodes = [];

            foreach ($masterRows as $masterRow) {
                $sourceBranchId = $masterRow['source_branch_id'] ?? null;

                if ($sourceBranchId === null) {
                    continue;
                }

                $branchCode = $this->requiredString($masterRow, 'branch_code', 'kode cabang');
                $branchName = $this->requiredString($masterRow, 'branch_name', 'nama cabang');

                if (mb_strlen($branchCode) > 16) {
                    throw new ErpStagingPullException("Kode cabang ERP {$branchCode} melebihi batas master Accounting.");
                }

                Branch::query()->updateOrCreate(
                    [
                        'legal_entity_id' => $legalEntity->id,
                        'code' => $branchCode,
                    ],
                    [
                        'name' => $branchName,
                        'is_active' => true,
                    ],
                );
                $branchCount++;
                $branchCodes[] = mb_strtoupper($branchCode);
            }

            return [
                'branch_codes' => array_values(array_unique($branchCodes)),
                'legal_entity' => $legalEntity->fresh(),
                'branch_count' => $branchCount,
            ];
        });
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $recordsByDataset
     * @param  list<string>  $datasets
     * @param  list<string>  $allowedBranchCodes
     * @return array{
     *     records: list<array{
     *         external_type: string,
     *         external_key: string,
     *         branch_code: string|null,
     *         external_updated_at: string|null,
     *         occurred_at: string|null,
     *         payload: array<string, mixed>
     *     }>,
     *     unmapped_branch_codes: list<string>,
     *     unmapped_branch_count: int
     * }
     */
    private function toStagingRecords(array $recordsByDataset, array $datasets, array $allowedBranchCodes): array
    {
        $records = [];
        $allowedBranches = array_fill_keys($allowedBranchCodes, true);
        $unmappedBranchCodes = [];
        $unmappedBranchCount = 0;

        foreach ($datasets as $dataset) {
            $definition = self::RECORD_DEFINITIONS[$dataset];

            foreach ($recordsByDataset[$dataset] as $record) {
                $externalKey = $this->requiredString(
                    $record,
                    $definition['id_column'],
                    'kunci sumber '.$dataset,
                );

                $sourceBranchCode = $this->nullableString($record['branch_code'] ?? null);
                $branchCode = $sourceBranchCode === null ? null : mb_strtoupper($sourceBranchCode);
                $payload = $this->normalizePayload($record);

                if ($branchCode !== null && ! isset($allowedBranches[$branchCode])) {
                    $unmappedBranchCount++;
                    $unmappedBranchCodes[] = $branchCode;
                    $branchCode = null;
                    $payload['staging_branch_assignment'] = 'unmapped_source_branch';
                    $payload = $this->normalizePayload($payload);
                }

                $records[] = [
                    'external_type' => $definition['external_type'],
                    'external_key' => $externalKey,
                    'branch_code' => $branchCode,
                    'external_updated_at' => null,
                    'occurred_at' => $this->nullableString($record['occurred_at'] ?? null),
                    'payload' => $payload,
                ];
            }
        }

        return [
            'records' => $records,
            'unmapped_branch_codes' => array_values(array_unique($unmappedBranchCodes)),
            'unmapped_branch_count' => $unmappedBranchCount,
        ];
    }

    private function normalizeEntityCode(string $entityCode): string
    {
        $normalized = mb_strtoupper(trim($entityCode));
        $configuredEntities = config('accounting.entities', []);

        if (! is_array($configuredEntities) || ! array_key_exists($normalized, $configuredEntities)) {
            throw new InvalidArgumentException('Entity harus salah satu dari BMM atau TMP.');
        }

        return $normalized;
    }

    private function normalizeDataset(string $dataset): string
    {
        $normalized = mb_strtolower(trim($dataset));
        $allowedDatasets = [self::DATASET_ALL, ...array_keys(self::DATASET_QUERIES)];

        if (! in_array($normalized, $allowedDatasets, true)) {
            throw new InvalidArgumentException(
                'Dataset harus all, customers, sales_invoices, payments, atau returns.',
            );
        }

        return $normalized;
    }

    private function normalizeLimit(int $limit): int
    {
        $maximumLimit = (int) config('accounting.staging.maximum_page_size', 100);

        if ($limit < 1 || $limit > $maximumLimit) {
            throw new InvalidArgumentException("Limit harus antara 1 dan {$maximumLimit}.");
        }

        return $limit;
    }

    /**
     * @param  list<object|array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeSourceRows(array $rows): array
    {
        return array_map(function (object|array $row): array {
            $values = is_array($row) ? $row : get_object_vars($row);

            return $this->normalizePayload($values);
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $payload[$key] = $value->format(DATE_ATOM);
            } elseif (is_array($value)) {
                $payload[$key] = $this->normalizePayload($value);
            }
        }

        ksort($payload, SORT_STRING);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function requiredString(array $record, string $key, string $label): string
    {
        $value = $this->nullableString($record[$key] ?? null);

        if ($value === null) {
            throw new ErpStagingPullException("Data sumber tidak memiliki {$label} yang valid.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
