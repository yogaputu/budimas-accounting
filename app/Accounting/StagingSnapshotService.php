<?php

namespace App\Accounting;

use App\Models\Access\AccessAudit;
use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\Accounting\StagedSourceRecord;
use App\Models\Accounting\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

/**
 * Stores snapshots that a supervised connector has already read from a source.
 *
 * This service only writes to the dedicated Accounting database. It never opens
 * or writes to PostgreSQL or SQL Server source connections, and it never creates
 * journal entries.
 */
final class StagingSnapshotService
{
    /**
     * @throws ValidationException
     */
    public function beginRun(
        DataSource $dataSource,
        ?LegalEntity $legalEntity = null,
        string $scope = 'full',
        ?User $actor = null,
        ?string $watermarkBefore = null,
    ): SyncRun {
        return DB::transaction(function () use ($actor, $dataSource, $legalEntity, $scope, $watermarkBefore): SyncRun {
            $lockedDataSource = DataSource::query()
                ->lockForUpdate()
                ->findOrFail($dataSource->id);

            $this->ensureReadOnlySource($lockedDataSource);
            $this->ensureLegalEntityIsActive($legalEntity);

            $syncRun = SyncRun::query()->create([
                'data_source_id' => $lockedDataSource->id,
                'legal_entity_id' => $legalEntity?->id,
                'initiated_by' => $actor?->id,
                'scope' => $this->normalizeScope($scope),
                'status' => SyncRun::STATUS_QUEUED,
                'watermark_before' => $this->nullableTrimmedString($watermarkBefore),
            ]);

            $this->writeAudit(
                syncRun: $syncRun,
                action: 'integration.sync_run_created',
                values: [
                    'data_source_id' => $lockedDataSource->id,
                    'legal_entity_id' => $legalEntity?->id,
                    'scope' => $syncRun->scope,
                    'status' => $syncRun->status,
                    'watermark_before' => $syncRun->watermark_before,
                ],
            );

            return $syncRun->load(['dataSource', 'legalEntity', 'initiatedBy']);
        });
    }

    /**
     * Store a bounded batch of records which was already read from a source.
     *
     * @param  list<array<string, mixed>>  $records
     * @throws ValidationException
     */
    public function stageBatch(SyncRun $syncRun, array $records): SyncRun
    {
        try {
            return DB::transaction(function () use ($records, $syncRun): SyncRun {
                $lockedSyncRun = SyncRun::query()
                    ->lockForUpdate()
                    ->findOrFail($syncRun->id);
                $lockedDataSource = DataSource::query()
                    ->lockForUpdate()
                    ->findOrFail($lockedSyncRun->data_source_id);

                $this->ensureReadOnlySource($lockedDataSource);
                $this->ensureRunAcceptsRecords($lockedSyncRun);

                if ($lockedSyncRun->isQueued()) {
                    $lockedSyncRun->forceFill([
                        'status' => SyncRun::STATUS_RUNNING,
                        'started_at' => now(),
                    ])->save();
                }

                foreach ($records as $record) {
                    $attributes = $this->normalizeRecord($record, $lockedSyncRun);

                    $stagedSourceRecord = StagedSourceRecord::query()->firstOrNew([
                        'data_source_id' => $lockedDataSource->id,
                        'legal_entity_id' => $attributes['legal_entity_id'],
                        'external_type' => $attributes['external_type'],
                        'external_key' => $attributes['external_key'],
                    ]);

                    $stagedSourceRecord->fill([
                        ...$attributes,
                        'sync_run_id' => $lockedSyncRun->id,
                        'data_source_id' => $lockedDataSource->id,
                        'synced_at' => now(),
                    ]);
                    $stagedSourceRecord->save();
                }

                $lockedSyncRun->increment('read_count', count($records));
                $lockedSyncRun->increment('upsert_count', count($records));

                return $lockedSyncRun->fresh([
                    'dataSource',
                    'legalEntity',
                    'initiatedBy',
                    'stagedSourceRecords',
                ]);
            });
        } catch (Throwable $exception) {
            $this->markFailed($syncRun, $exception);

            throw $exception;
        }
    }

    /**
     * @throws ValidationException
     */
    public function completeRun(SyncRun $syncRun, ?string $watermarkAfter = null): SyncRun
    {
        return DB::transaction(function () use ($syncRun, $watermarkAfter): SyncRun {
            $lockedSyncRun = SyncRun::query()
                ->lockForUpdate()
                ->findOrFail($syncRun->id);

            if ($lockedSyncRun->isCompleted() || $lockedSyncRun->isFailed()) {
                throw ValidationException::withMessages([
                    'sync_run' => 'Sync run yang telah selesai tidak dapat diubah.',
                ]);
            }

            $startedAt = $lockedSyncRun->started_at ?? now();
            $lockedSyncRun->forceFill([
                'status' => SyncRun::STATUS_COMPLETED,
                'started_at' => $startedAt,
                'finished_at' => now(),
                'watermark_after' => $this->nullableTrimmedString($watermarkAfter),
            ])->save();

            $this->writeAudit(
                syncRun: $lockedSyncRun,
                action: 'integration.sync_run_completed',
                values: [
                    'status' => $lockedSyncRun->status,
                    'read_count' => $lockedSyncRun->read_count,
                    'upsert_count' => $lockedSyncRun->upsert_count,
                    'error_count' => $lockedSyncRun->error_count,
                    'watermark_after' => $lockedSyncRun->watermark_after,
                ],
            );

            return $lockedSyncRun->fresh(['dataSource', 'legalEntity', 'initiatedBy']);
        });
    }

    private function ensureReadOnlySource(DataSource $dataSource): void
    {
        if (! $dataSource->is_active) {
            throw ValidationException::withMessages([
                'data_source' => 'Sumber data tidak aktif.',
            ]);
        }

        if (! in_array($dataSource->access_mode, ['read_only', 'read_only_worker'], true)) {
            throw ValidationException::withMessages([
                'data_source' => 'Sumber data harus dikonfigurasi sebagai read-only.',
            ]);
        }
    }

    private function ensureLegalEntityIsActive(?LegalEntity $legalEntity): void
    {
        if ($legalEntity === null) {
            return;
        }

        $isActive = LegalEntity::query()
            ->whereKey($legalEntity->id)
            ->where('is_active', true)
            ->exists();

        if (! $isActive) {
            throw ValidationException::withMessages([
                'legal_entity' => 'Perusahaan tidak ditemukan atau tidak aktif.',
            ]);
        }
    }

    private function ensureRunAcceptsRecords(SyncRun $syncRun): void
    {
        if (! $syncRun->isQueued() && ! $syncRun->isRunning()) {
            throw ValidationException::withMessages([
                'sync_run' => 'Sync run ini tidak dapat menerima snapshot baru.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     legal_entity_id: int|null,
     *     branch_id: int|null,
     *     external_type: string,
     *     external_key: string,
     *     external_updated_at: string|null,
     *     content_hash: string,
     *     payload: array<mixed>,
     *     occurred_at: string|null
     * }
     * @throws ValidationException
     */
    private function normalizeRecord(array $record, SyncRun $syncRun): array
    {
        $validated = Validator::make($record, [
            'legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'external_type' => ['required', 'string', 'max:64'],
            'external_key' => ['required', 'string', 'max:128'],
            'external_updated_at' => ['nullable', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ])->validate();

        $externalType = trim((string) $validated['external_type']);
        $externalKey = trim((string) $validated['external_key']);

        if ($externalType === '' || $externalKey === '') {
            throw ValidationException::withMessages([
                'external_type' => 'Tipe data sumber wajib diisi.',
                'external_key' => 'Kunci data sumber wajib diisi.',
            ]);
        }

        $legalEntityId = $validated['legal_entity_id'] ?? $syncRun->legal_entity_id;
        $branchId = $validated['branch_id'] ?? null;

        if ($syncRun->legal_entity_id !== null
            && $legalEntityId !== null
            && (int) $syncRun->legal_entity_id !== (int) $legalEntityId) {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'Snapshot harus berada pada perusahaan yang sama dengan sync run.',
            ]);
        }

        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);

            if ($legalEntityId !== null && (int) $branch->legal_entity_id !== (int) $legalEntityId) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Cabang snapshot harus berasal dari perusahaan yang sama.',
                ]);
            }

            $legalEntityId ??= $branch->legal_entity_id;
        }

        try {
            $contentHash = hash('sha256', json_encode(
                $this->canonicalizePayload($validated['payload']),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'payload' => 'Payload snapshot harus dapat disimpan sebagai JSON.',
            ]);
        }

        return [
            'legal_entity_id' => $legalEntityId === null ? null : (int) $legalEntityId,
            'branch_id' => $branchId === null ? null : (int) $branchId,
            'external_type' => $externalType,
            'external_key' => $externalKey,
            'external_updated_at' => $this->nullableTrimmedString($validated['external_updated_at'] ?? null),
            'content_hash' => $contentHash,
            'payload' => $validated['payload'],
            'occurred_at' => isset($validated['occurred_at'])
                ? CarbonImmutable::parse($validated['occurred_at'])->toDateTimeString()
                : null,
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function canonicalizePayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_map(
                fn (mixed $value): mixed => is_array($value) ? $this->canonicalizePayload($value) : $value,
                $payload,
            );
        }

        ksort($payload, SORT_STRING);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalizePayload($value);
            }
        }

        return $payload;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeScope(string $scope): string
    {
        $normalizedScope = trim($scope);

        if ($normalizedScope === '' || Str::length($normalizedScope) > 64) {
            throw ValidationException::withMessages([
                'scope' => 'Scope sync wajib diisi dengan maksimal 64 karakter.',
            ]);
        }

        return $normalizedScope;
    }

    private function nullableTrimmedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmedValue = trim($value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }

    private function markFailed(SyncRun $syncRun, Throwable $exception): void
    {
        if ($syncRun->id === null) {
            return;
        }

        DB::transaction(function () use ($exception, $syncRun): void {
            $lockedSyncRun = SyncRun::query()
                ->lockForUpdate()
                ->find($syncRun->id);

            if ($lockedSyncRun === null || $lockedSyncRun->isCompleted() || $lockedSyncRun->isFailed()) {
                return;
            }

            $lockedSyncRun->forceFill([
                'status' => SyncRun::STATUS_FAILED,
                'started_at' => $lockedSyncRun->started_at ?? now(),
                'finished_at' => now(),
                'error_count' => $lockedSyncRun->error_count + 1,
                'error_summary' => Str::limit($exception->getMessage(), 4000),
            ])->save();

            $this->writeAudit(
                syncRun: $lockedSyncRun,
                action: 'integration.sync_run_failed',
                values: [
                    'status' => $lockedSyncRun->status,
                    'error_count' => $lockedSyncRun->error_count,
                    'error_summary' => $lockedSyncRun->error_summary,
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeAudit(SyncRun $syncRun, string $action, array $values): void
    {
        AccessAudit::query()->create([
            'actor_id' => $syncRun->initiated_by,
            'action' => $action,
            'subject_type' => SyncRun::class,
            'subject_id' => $syncRun->id,
            'new_values' => $values,
        ]);
    }
}
