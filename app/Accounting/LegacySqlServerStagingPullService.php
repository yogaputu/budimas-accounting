<?php

namespace App\Accounting;

use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class LegacySqlServerStagingPullService
{
    public const SOURCE_CONNECTION = 'legacy_sqlserver';

    public const SOURCES = [
        'bmm_solo' => ['entity_code' => 'BMM', 'branch_code' => 'SLO', 'label' => 'BMM · Solo'],
        'tmp_solo' => ['entity_code' => 'TMP', 'branch_code' => 'SLO', 'label' => 'TMP · Solo'],
        'bmm_klaten' => ['entity_code' => 'BMM', 'branch_code' => 'KLT', 'label' => 'BMM · Klaten'],
        'tmp_klaten' => ['entity_code' => 'TMP', 'branch_code' => 'KLT', 'label' => 'TMP · Klaten'],
        'bmm_wonogiri' => ['entity_code' => 'BMM', 'branch_code' => 'WNG', 'label' => 'BMM · Wonogiri'],
        'tmp_wonogiri' => ['entity_code' => 'TMP', 'branch_code' => 'WNG', 'label' => 'TMP · Wonogiri'],
    ];

    public const DATASETS = [
        'customers' => 'Customer',
        'sales_invoices' => 'Faktur Penjualan',
        'payments' => 'Kwitansi Pembayaran',
        'returns' => 'Retur Penjualan',
    ];

    public const EXTERNAL_TYPES = [
        'customers' => 'legacy.customer',
        'sales_invoices' => 'legacy.sales_invoice',
        'payments' => 'legacy.invoice_payment',
        'returns' => 'legacy.return_request',
    ];

    private const SOURCE_READ_ERROR = 'Sumber SQL Server belum dapat dibaca. Periksa koneksi worker dan izin baca sumber, lalu coba lagi.';

    public function __construct(private readonly StagingBatchIngestService $stagingBatchIngestService) {}

    /**
     * Read a bounded worker snapshot and persist it only in Accounting staging.
     * Existing company and branch masters are required and are never changed.
     *
     * @return array{source_key: string, source_label: string, entity: string, entity_code: string, branch_code: string, dataset: string, limit: int, branch_count: int, counts: array<string, int>, read_count: int, upsert_count: int, sync_run_id: int, unmapped_branch_codes: list<string>, unmapped_branch_count: int}
     */
    public function pull(string $sourceKey, string $dataset, int $limit, ?User $actor = null): array
    {
        if (! isset(self::SOURCES[$sourceKey], self::DATASETS[$dataset]) || $limit < 1 || $limit > 100) {
            throw new LegacySqlServerStagingPullException('Pilih sumber SQL Server, jenis data, dan batas 1–100 yang valid.');
        }

        $source = self::SOURCES[$sourceKey];
        $entity = $this->activeLocalEntity($source);
        $dataSource = DataSource::query()
            ->where('key', self::SOURCE_CONNECTION)
            ->where('is_active', true)
            ->whereIn('access_mode', ['read_only', 'read_only_worker'])
            ->first();

        if ($dataSource === null) {
            throw new LegacySqlServerStagingPullException('Sumber SQL Server belum aktif sebagai sumber read-only Accounting.');
        }

        $startedAt = now();
        $scope = 'sqlserver:'.$sourceKey.':'.$dataset;

        try {
            $records = $this->readSnapshot($sourceKey, $dataset, $limit);
            $this->activeLocalEntity($source);
        } catch (Throwable $exception) {
            SyncRun::query()->create([
                'data_source_id' => $dataSource->id,
                'legal_entity_id' => $entity->id,
                'initiated_by' => $actor?->id,
                'scope' => $scope,
                'status' => SyncRun::STATUS_FAILED,
                'read_count' => 0,
                'upsert_count' => 0,
                'error_count' => 1,
                'error_summary' => self::SOURCE_READ_ERROR,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
            Log::warning('Accounting SQL Server source read failed.', [
                'exception_class' => $exception::class,
                'scope' => $scope,
            ]);

            throw new LegacySqlServerStagingPullException(self::SOURCE_READ_ERROR);
        }

        $ingested = $this->stagingBatchIngestService->ingest([
            'source_key' => self::SOURCE_CONNECTION,
            'legal_entity_code' => $source['entity_code'],
            'scope' => $scope,
            'records' => $records,
        ], $actor);

        return [
            'source_key' => $sourceKey,
            'source_label' => $source['label'],
            'entity' => $source['entity_code'],
            'entity_code' => $source['entity_code'],
            'branch_code' => $source['branch_code'],
            'dataset' => $dataset,
            'limit' => $limit,
            'branch_count' => 1,
            'counts' => [$dataset => count($records)],
            'read_count' => $ingested['read_count'],
            'upsert_count' => $ingested['upsert_count'],
            'sync_run_id' => (int) $ingested['sync_run']->id,
            'unmapped_branch_codes' => [],
            'unmapped_branch_count' => 0,
        ];
    }

    /**
     * @param  array{entity_code: string, branch_code: string, label: string}  $source
     */
    private function activeLocalEntity(array $source): LegalEntity
    {
        $entity = LegalEntity::query()
            ->where('code', $source['entity_code'])
            ->where('is_active', true)
            ->first();

        if ($entity === null || ! Branch::query()
            ->where('legal_entity_id', $entity->id)
            ->where('code', $source['branch_code'])
            ->where('is_active', true)
            ->exists()) {
            throw new LegacySqlServerStagingPullException('Perusahaan dan cabang sumber harus tersedia dan aktif di Accounting.');
        }

        return $entity;
    }

    /**
     * @return list<array{external_type: string, external_key: string, branch_code: string, occurred_at: string|null, payload: array<mixed>}>
     */
    private function readSnapshot(string $sourceKey, string $dataset, int $limit): array
    {
        $configuration = config('accounting.staging.sqlserver_worker');

        if (! is_array($configuration) || ($configuration['enabled'] ?? false) !== true) {
            throw new LegacySqlServerStagingPullException('Worker SQL Server belum diaktifkan.');
        }

        foreach (['ssh_host', 'ssh_user', 'ssh_identity_file', 'remote_python', 'remote_php', 'remote_config'] as $key) {
            if (! is_string($configuration[$key] ?? null)
                || trim($configuration[$key]) === ''
                || preg_match('/[\x00\r\n]/', $configuration[$key]) === 1) {
                throw new LegacySqlServerStagingPullException('Konfigurasi worker SQL Server belum lengkap.');
            }
        }

        $port = filter_var($configuration['ssh_port'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new LegacySqlServerStagingPullException('Konfigurasi worker SQL Server belum lengkap.');
        }

        $workerPath = base_path('scripts/pull-legacy-sqlserver.py');
        if (! is_readable($workerPath)) {
            throw new LegacySqlServerStagingPullException('Worker SQL Server belum tersedia.');
        }
        $worker = file_get_contents($workerPath);
        if (! is_string($worker) || $worker === '') {
            throw new LegacySqlServerStagingPullException('Worker SQL Server belum tersedia.');
        }

        $remoteCommand = implode(' ', array_map('escapeshellarg', [
            $configuration['remote_python'], '-',
            '--config', $configuration['remote_config'],
            '--php', $configuration['remote_php'],
            '--source', $sourceKey,
            '--dataset', $dataset,
            '--limit', (string) $limit,
        ]));
        $result = Process::timeout(45)->input($worker)->run([
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'ConnectTimeout=8',
            '-p', (string) $port,
            '-i', $configuration['ssh_identity_file'],
            $configuration['ssh_user'].'@'.$configuration['ssh_host'],
            $remoteCommand,
        ]);

        if (! $result->successful()) {
            throw new LegacySqlServerStagingPullException(self::SOURCE_READ_ERROR);
        }

        return $this->normalizeRecords(
            json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR),
            $sourceKey,
            $dataset,
            $limit,
        );
    }

    /**
     * @return list<array{external_type: string, external_key: string, branch_code: string, occurred_at: string|null, payload: array<mixed>}>
     */
    private function normalizeRecords(mixed $snapshot, string $sourceKey, string $dataset, int $limit): array
    {
        $source = self::SOURCES[$sourceKey];
        if (! is_array($snapshot)
            || ($snapshot['source_key'] ?? null) !== $sourceKey
            || ($snapshot['entity_code'] ?? null) !== $source['entity_code']
            || ($snapshot['branch_code'] ?? null) !== $source['branch_code']
            || ($snapshot['dataset'] ?? null) !== $dataset
            || ! is_array($snapshot['records'] ?? null)
            || ! array_is_list($snapshot['records'])
            || count($snapshot['records']) > $limit) {
            throw new LegacySqlServerStagingPullException('Snapshot SQL Server tidak sesuai sumber yang dipilih.');
        }

        $records = [];
        $seen = [];
        foreach ($snapshot['records'] as $record) {
            if (! is_array($record) || ! is_scalar($record['external_key'] ?? null)
                || ! is_array($record['payload'] ?? null)
                || (array_is_list($record['payload']) && $record['payload'] !== [])) {
                throw new LegacySqlServerStagingPullException('Format rekaman SQL Server tidak valid.');
            }

            $rawKey = trim((string) $record['external_key']);
            $externalKey = $sourceKey.':'.$rawKey;
            $occurredAt = $record['occurred_at'] ?? null;
            if ($rawKey === '' || mb_strlen($externalKey) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $rawKey) === 1
                || isset($seen[$externalKey])
                || ($occurredAt !== null && ! is_string($occurredAt))
                || Validator::make(['occurred_at' => $occurredAt], ['occurred_at' => ['nullable', 'date']])->fails()) {
                throw new LegacySqlServerStagingPullException('Identitas atau tanggal rekaman SQL Server tidak valid.');
            }

            $seen[$externalKey] = true;
            $records[] = [
                'external_type' => self::EXTERNAL_TYPES[$dataset],
                'external_key' => $externalKey,
                'branch_code' => $source['branch_code'],
                'occurred_at' => $occurredAt,
                'payload' => $record['payload'],
            ];
        }

        return $records;
    }
}
