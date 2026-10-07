<?php

namespace App\Accounting;

use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StagingBatchIngestService
{
    /**
     * Persist a bounded snapshot from an approved read-only source.
     *
     * This service only writes into Accounting's local staging tables. It
     * never opens, changes, or posts anything to an ERP or SQL Server source.
     *
     * @param  array{
     *     source_key: string,
     *     legal_entity_code: string,
     *     scope: string,
     *     watermark_before?: string|null,
     *     watermark_after?: string|null,
     *     records: list<array{
     *         external_type: string,
     *         external_key: string,
     *         branch_code?: string|null,
     *         external_updated_at?: string|null,
     *         occurred_at?: string|null,
     *         payload: array<mixed>
     *     }>
     * } $attributes
     * @return array{read_count: int, sync_run: SyncRun, upsert_count: int}
     */
    public function ingest(array $attributes, ?User $actor = null): array
    {
        $dataSource = DataSource::query()
            ->where('key', $attributes['source_key'])
            ->where('is_active', true)
            ->first();

        if ($dataSource === null || ! str_starts_with($dataSource->access_mode, 'read_only')) {
            throw ValidationException::withMessages([
                'source_key' => 'Sumber tidak aktif atau bukan sumber read-only yang disetujui.',
            ]);
        }

        $legalEntity = LegalEntity::query()
            ->where('code', $attributes['legal_entity_code'])
            ->where('is_active', true)
            ->first();

        if ($legalEntity === null) {
            throw ValidationException::withMessages([
                'legal_entity_code' => 'Perusahaan Accounting tidak ditemukan atau tidak aktif.',
            ]);
        }

        $syncRun = SyncRun::query()->create([
            'data_source_id' => $dataSource->id,
            'legal_entity_id' => $legalEntity->id,
            'initiated_by' => $actor?->id,
            'scope' => $attributes['scope'],
            'status' => SyncRun::STATUS_RUNNING,
            'watermark_before' => $attributes['watermark_before'] ?? null,
            'started_at' => now(),
        ]);

        try {
            $result = DB::transaction(function () use ($attributes, $dataSource, $legalEntity, $syncRun): array {
                $branchesByCode = Branch::query()
                    ->where('legal_entity_id', $legalEntity->id)
                    ->get(['id', 'code'])
                    ->mapWithKeys(static fn (Branch $branch): array => [
                        mb_strtoupper(trim($branch->code)) => $branch->id,
                    ]);
                $upsertCount = 0;

                foreach ($attributes['records'] as $record) {
                    $branchCode = $record['branch_code'] ?? null;
                    $branchId = null;

                    if (is_string($branchCode) && $branchCode !== '') {
                        $branchId = $branchesByCode->get(mb_strtoupper(trim($branchCode)));

                        if ($branchId === null) {
                            throw ValidationException::withMessages([
                                'records' => "Cabang {$branchCode} tidak tersedia pada perusahaan {$legalEntity->code}.",
                            ]);
                        }
                    }

                    $payload = $this->canonicalizePayload($record['payload']);
                    $contentHash = hash(
                        'sha256',
                        json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    );

                    StagedSourceRecord::query()->updateOrCreate(
                        [
                            'data_source_id' => $dataSource->id,
                            'legal_entity_id' => $legalEntity->id,
                            'external_type' => $record['external_type'],
                            'external_key' => $record['external_key'],
                        ],
                        [
                            'sync_run_id' => $syncRun->id,
                            'legal_entity_id' => $legalEntity->id,
                            'branch_id' => $branchId,
                            'external_updated_at' => $record['external_updated_at'] ?? null,
                            'content_hash' => $contentHash,
                            'payload' => $payload,
                            'occurred_at' => $record['occurred_at'] ?? null,
                            'synced_at' => now(),
                        ],
                    );
                    $upsertCount++;
                }

                $syncRun->forceFill([
                    'status' => SyncRun::STATUS_COMPLETED,
                    'watermark_after' => $attributes['watermark_after'] ?? null,
                    'read_count' => count($attributes['records']),
                    'upsert_count' => $upsertCount,
                    'finished_at' => now(),
                ])->save();

                return [
                    'read_count' => count($attributes['records']),
                    'upsert_count' => $upsertCount,
                ];
            });
        } catch (\Throwable $exception) {
            $syncRun->forceFill([
                'status' => SyncRun::STATUS_FAILED,
                'error_count' => 1,
                'error_summary' => 'Batch staging gagal divalidasi atau disimpan.',
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return [
            ...$result,
            'sync_run' => $syncRun->fresh(),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function canonicalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalizePayload($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }

        return $payload;
    }
}
