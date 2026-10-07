<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\ErpStagingPullService;
use App\Accounting\LegacySqlServerStagingPullService;
use App\Accounting\SourceReadinessService;
use App\Http\Controllers\Controller;
use App\Models\Access\RoleAssignment;
use App\Models\Accounting\Branch;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use App\PermissionCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StagingDataController extends Controller
{
    private const PAGE_SIZE = 50;

    public function __construct(private readonly SourceReadinessService $sourceReadiness) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $accessScope = $this->accessScope($user);
        $entities = $this->availableEntities($accessScope);

        abort_if($entities->isEmpty(), 403, 'Anda tidak memiliki hak akses untuk melihat data staging.');

        $selectedEntity = $this->selectedEntity($request, $entities, $accessScope['is_global']);
        $sources = DataSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'kind', 'access_mode']);
        $selectedSourceId = $this->selectedSourceId($request, $sources);

        $scopedRecords = $this->scopedStagedRecordsQuery($selectedEntity, $accessScope);
        $sourceRecordCounts = (clone $scopedRecords)
            ->select('data_source_id', DB::raw('COUNT(*) AS record_count'))
            ->groupBy('data_source_id')
            ->pluck('record_count', 'data_source_id');

        $availableExternalTypes = (clone $scopedRecords)
            ->when(
                $selectedSourceId !== null,
                static fn (Builder $query) => $query->where('data_source_id', $selectedSourceId),
            )
            ->distinct()
            ->orderBy('external_type')
            ->pluck('external_type');
        $selectedExternalType = $this->selectedExternalType($request, $availableExternalTypes);
        $search = Str::limit($request->string('search')->trim()->toString(), 100, '');

        $filteredRecords = (clone $scopedRecords)
            ->when(
                $selectedSourceId !== null,
                static fn (Builder $query) => $query->where('staged_source_records.data_source_id', $selectedSourceId),
            )
            ->when(
                $selectedExternalType !== null,
                static fn (Builder $query) => $query->where('staged_source_records.external_type', $selectedExternalType),
            )
            ->when(
                $search !== '',
                static fn (Builder $query) => $query->where('staged_source_records.external_key', 'like', "%{$search}%"),
            );

        $records = $this->records($filteredRecords);
        $latestRuns = $this->latestSyncRuns($selectedEntity, $accessScope);
        $sourceReadiness = $sources
            ->mapWithKeys(fn (DataSource $source): array => [$source->id => $this->sourceReadiness->inspect($source)]);
        $sourceStatuses = $this->sourceStatuses(
            $sources,
            $sourceRecordCounts,
            $latestRuns,
            $sourceReadiness,
        );
        $canManageSync = $accessScope['is_global']
            && $user->hasGlobalPermission(PermissionCode::SyncManage->value);
        $activeBranches = Branch::query()
            ->whereIn('legal_entity_id', $entities->pluck('id'))
            ->where('is_active', true)
            ->get(['legal_entity_id', 'code']);
        $sqlServerSources = collect(LegacySqlServerStagingPullService::SOURCES)
            ->filter(static function (array $source) use ($entities, $activeBranches): bool {
                $entity = $entities->firstWhere('code', $source['entity_code']);

                return $entity !== null && $activeBranches->contains(
                    static fn (Branch $branch): bool => $branch->legal_entity_id === $entity->id
                        && $branch->code === $source['branch_code'],
                );
            })
            ->all();

        return view('accounting.staging.index', [
            'canManageSync' => $canManageSync,
            'sqlServerSources' => $sqlServerSources,
            'sqlServerDatasetOptions' => LegacySqlServerStagingPullService::DATASETS,
            'sqlServerWorkerConfigured' => (bool) ($sourceStatuses->firstWhere('key', 'legacy_sqlserver')['readiness']['configured'] ?? false),
            'canViewDashboard' => $user->hasGlobalPermission(PermissionCode::AccountingDashboardView->value),
            'datasetOptions' => $availableExternalTypes->mapWithKeys(
                fn (string $externalType): array => [$externalType => $this->datasetLabel($externalType)],
            ),
            'entities' => $entities,
            'filters' => [
                'data_source_id' => $selectedSourceId,
                'external_type' => $selectedExternalType,
                'legal_entity_id' => $selectedEntity?->id,
                'search' => $search,
            ],
            'records' => $records,
            'scopeLabel' => $this->scopeLabel($selectedEntity, $accessScope),
            'selectedEntity' => $selectedEntity,
            'sourceStatuses' => $sourceStatuses,
            'sources' => $sources,
            'summary' => [
                'active_sources' => $sources->count(),
                'failed_sources' => $sourceStatuses->where('status', 'failed')->count(),
                'records' => (clone $scopedRecords)->count(),
                'synced_today' => (clone $scopedRecords)
                    ->where('synced_at', '>=', now()->startOfDay())
                    ->count(),
            ],
            'syncEntities' => $entities->whereIn('code', ['BMM', 'TMP'])->values(),
            'pullDatasetOptions' => [
                ErpStagingPullService::DATASET_CUSTOMERS => 'Pelanggan',
                ErpStagingPullService::DATASET_SALES_INVOICES => 'Faktur penjualan',
                ErpStagingPullService::DATASET_PAYMENTS => 'Klaim pembayaran Mobile Sales',
                ErpStagingPullService::DATASET_RETURNS => 'Retur penjualan',
            ],
        ]);
    }

    /**
     * @return array{is_global: bool, entity_ids: list<int>, branch_ids_by_entity: array<int, list<int>>}
     */
    private function accessScope(User $user): array
    {
        $assignments = $user->roleAssignments()
            ->with('role.permissions')
            ->get()
            ->filter(static fn (RoleAssignment $assignment): bool => $assignment->isActive()
                && $assignment->role->permissions->contains('code', PermissionCode::SystemHealthView->value))
            ->values();

        $isGlobal = $assignments->contains(
            static fn (RoleAssignment $assignment): bool => $assignment->scope_key === 'global',
        );
        $entityIds = $assignments
            ->where('scope_key', 'entity')
            ->pluck('legal_entity_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $branchIds = $assignments
            ->where('scope_key', 'branch')
            ->pluck('branch_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $branchIdsByEntity = [];

        if ($branchIds !== []) {
            Branch::query()
                ->whereIn('id', $branchIds)
                ->get(['id', 'legal_entity_id'])
                ->each(static function (Branch $branch) use (&$branchIdsByEntity): void {
                    $branchIdsByEntity[$branch->legal_entity_id] ??= [];
                    $branchIdsByEntity[$branch->legal_entity_id][] = $branch->id;
                });
        }

        return [
            'is_global' => $isGlobal,
            'entity_ids' => $entityIds,
            'branch_ids_by_entity' => $branchIdsByEntity,
        ];
    }

    /**
     * @param  array{is_global: bool, entity_ids: list<int>, branch_ids_by_entity: array<int, list<int>>}  $accessScope
     * @return Collection<int, LegalEntity>
     */
    private function availableEntities(array $accessScope): Collection
    {
        $query = LegalEntity::query()
            ->where('is_active', true)
            ->orderBy('code');

        if (! $accessScope['is_global']) {
            $entityIds = array_values(array_unique([
                ...$accessScope['entity_ids'],
                ...array_keys($accessScope['branch_ids_by_entity']),
            ]));

            if ($entityIds === []) {
                return collect();
            }

            $query->whereIn('id', $entityIds);
        }

        return $query->get(['id', 'code', 'name']);
    }

    /**
     * @param  Collection<int, LegalEntity>  $entities
     */
    private function selectedEntity(
        Request $request,
        Collection $entities,
        bool $isGlobal,
    ): ?LegalEntity {
        $selectedEntityId = $request->integer('legal_entity_id');

        abort_if(
            $request->filled('legal_entity_id') && $selectedEntityId === 0,
            404,
            'Perusahaan tidak ditemukan.',
        );

        if ($selectedEntityId === 0) {
            return $isGlobal ? null : $entities->first();
        }

        $selectedEntity = $entities->firstWhere('id', $selectedEntityId);

        abort_if($selectedEntity === null, 403, 'Anda tidak memiliki hak akses untuk perusahaan ini.');

        return $selectedEntity;
    }

    /**
     * @param  Collection<int, DataSource>  $sources
     */
    private function selectedSourceId(Request $request, Collection $sources): ?int
    {
        if (! $request->filled('data_source_id')) {
            return null;
        }

        $sourceId = $request->integer('data_source_id');

        abort_if(
            $sourceId === 0 || ! $sources->contains('id', $sourceId),
            404,
            'Sumber data tidak ditemukan.',
        );

        return $sourceId;
    }

    /**
     * @param  Collection<int, string>  $externalTypes
     */
    private function selectedExternalType(Request $request, Collection $externalTypes): ?string
    {
        $externalType = $request->string('external_type')->trim()->toString();

        if ($externalType === '') {
            return null;
        }

        abort_if(
            ! $externalTypes->contains($externalType),
            404,
            'Tipe data staging tidak ditemukan.',
        );

        return $externalType;
    }

    /**
     * @param  array{is_global: bool, entity_ids: list<int>, branch_ids_by_entity: array<int, list<int>>}  $accessScope
     */
    private function scopedStagedRecordsQuery(?LegalEntity $selectedEntity, array $accessScope): Builder
    {
        $query = DB::table('staged_source_records');

        if ($selectedEntity === null) {
            abort_unless($accessScope['is_global'], 403, 'Scope data staging tidak valid.');

            return $query;
        }

        $query->where('staged_source_records.legal_entity_id', $selectedEntity->id);

        if (
            ! $accessScope['is_global']
            && ! in_array($selectedEntity->id, $accessScope['entity_ids'], true)
        ) {
            $branchIds = $accessScope['branch_ids_by_entity'][$selectedEntity->id] ?? [];

            abort_if($branchIds === [], 403, 'Anda tidak memiliki hak akses untuk perusahaan ini.');

            $query->whereIn('staged_source_records.branch_id', $branchIds);
        }

        return $query;
    }

    /**
     * @param  array{is_global: bool, entity_ids: list<int>, branch_ids_by_entity: array<int, list<int>>}  $accessScope
     * @return Collection<int, object>
     */
    private function latestSyncRuns(?LegalEntity $selectedEntity, array $accessScope): Collection
    {
        if (
            $selectedEntity !== null
            && ! $accessScope['is_global']
            && ! in_array($selectedEntity->id, $accessScope['entity_ids'], true)
        ) {
            return collect();
        }

        $runScope = DB::table('sync_runs');

        if ($selectedEntity !== null) {
            $runScope->where('legal_entity_id', $selectedEntity->id);
        } elseif (! $accessScope['is_global']) {
            return collect();
        }

        $latestRunIds = (clone $runScope)
            ->selectRaw('data_source_id, MAX(id) AS id')
            ->groupBy('data_source_id');

        return DB::table('sync_runs')
            ->joinSub($latestRunIds, 'latest_sync_runs', static function ($join): void {
                $join->on('sync_runs.id', '=', 'latest_sync_runs.id');
            })
            ->leftJoin('legal_entities', 'legal_entities.id', '=', 'sync_runs.legal_entity_id')
            ->select([
                'sync_runs.data_source_id',
                'sync_runs.status',
                'sync_runs.scope',
                'sync_runs.read_count',
                'sync_runs.upsert_count',
                'sync_runs.error_count',
                'sync_runs.error_summary',
                'sync_runs.started_at',
                'sync_runs.finished_at',
                'legal_entities.code as legal_entity_code',
            ])
            ->get()
            ->keyBy('data_source_id');
    }

    /**
     * @param  Collection<int, DataSource>  $sources
     * @param  Collection<int|string, int|string>  $sourceRecordCounts
     * @param  Collection<int|string, object>  $latestRuns
     * @param  Collection<int|string, array{configured: bool, connection: string|null, database: string|null, host: string|null, message: string, mode: string, network_status: string, port: int|string|null, username_configured: bool}>  $sourceReadiness
     * @return Collection<int, array{id: int, key: string, name: string, kind: string, access_mode: string, record_count: int, status: string, status_label: string, status_tone: string, latest_run: object|null, readiness: array{configured: bool, connection: string|null, database: string|null, host: string|null, message: string, mode: string, network_status: string, port: int|string|null, username_configured: bool}}>
     */
    private function sourceStatuses(
        Collection $sources,
        Collection $sourceRecordCounts,
        Collection $latestRuns,
        Collection $sourceReadiness,
    ): Collection {
        return $sources
            ->map(function (DataSource $source) use ($sourceRecordCounts, $latestRuns, $sourceReadiness): array {
                $latestRun = $latestRuns->get($source->id);
                $status = $latestRun?->status ?? 'not_started';

                if ($latestRun !== null) {
                    $latestRun->started_at_label = $this->timestampLabel($latestRun->started_at);
                    $latestRun->finished_at_label = $this->timestampLabel($latestRun->finished_at);
                    $latestRun->error_summary = $latestRun->error_summary === null
                        ? null
                        : Str::limit((string) $latestRun->error_summary, 240);
                }

                return [
                    'id' => $source->id,
                    'key' => $source->key,
                    'name' => $source->name,
                    'kind' => $source->kind,
                    'access_mode' => $source->access_mode,
                    'record_count' => (int) ($sourceRecordCounts->get($source->id) ?? 0),
                    'status' => $status,
                    'status_label' => $this->syncStatusLabel($status),
                    'status_tone' => $this->syncStatusTone($status),
                    'latest_run' => $latestRun,
                    'readiness' => $sourceReadiness->get($source->id),
                ];
            })
            ->values();
    }

    private function syncStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Selesai',
            'failed' => 'Perlu ditinjau',
            'running' => 'Berjalan',
            'queued' => 'Menunggu',
            default => 'Belum ada run',
        };
    }

    private function syncStatusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'bg-emerald-50 text-emerald-700',
            'failed' => 'bg-rose-50 text-rose-700',
            'running' => 'bg-sky-50 text-sky-700',
            'queued' => 'bg-amber-50 text-amber-700',
            default => 'bg-slate-100 text-slate-600',
        };
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    private function records(Builder $filteredRecords): LengthAwarePaginator
    {
        $records = $filteredRecords
            ->join('data_sources', 'data_sources.id', '=', 'staged_source_records.data_source_id')
            ->leftJoin('legal_entities', 'legal_entities.id', '=', 'staged_source_records.legal_entity_id')
            ->leftJoin('branches', 'branches.id', '=', 'staged_source_records.branch_id')
            ->select([
                'staged_source_records.id',
                'staged_source_records.external_type',
                'staged_source_records.external_key',
                'staged_source_records.external_updated_at',
                'staged_source_records.content_hash',
                'staged_source_records.payload',
                'staged_source_records.occurred_at',
                'staged_source_records.synced_at',
                'data_sources.key as data_source_key',
                'data_sources.name as data_source_name',
                'legal_entities.code as legal_entity_code',
                'branches.code as branch_code',
                'branches.name as branch_name',
            ])
            ->orderByDesc('staged_source_records.synced_at')
            ->orderByDesc('staged_source_records.id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        $records->getCollection()->transform(function (object $record): object {
            $record->dataset_label = $this->datasetLabel((string) $record->external_type);
            $record->external_updated_at_label = $this->timestampLabel($record->external_updated_at);
            $record->occurred_at_label = $this->timestampLabel($record->occurred_at);
            $record->synced_at_label = $this->timestampLabel($record->synced_at);
            $record->branch_label = $record->branch_code === null
                ? '—'
                : "{$record->branch_code} — {$record->branch_name}";
            $record->payload_preview = $this->payloadPreview($record->payload);

            return $record;
        });

        return $records;
    }

    private function datasetLabel(string $externalType): string
    {
        return match (Str::lower($externalType)) {
            'customer', 'customers', 'erp.customer', 'legacy.customer' => 'Customer / Toko',
            'product', 'products', 'erp.product' => 'Produk',
            'branch', 'branches', 'erp.branch' => 'Cabang',
            'sales_invoice', 'sales_invoices', 'invoice', 'invoices', 'erp.sales_invoice', 'legacy.sales_invoice' => 'Faktur Penjualan',
            'purchase_invoice', 'purchase_invoices', 'purchase_bill', 'purchase_bills', 'erp.purchase_invoice' => 'Faktur Pembelian',
            'payment', 'payments', 'erp.payment', 'erp.mobile_payment_claim', 'legacy.invoice_payment' => 'Pembayaran',
            'return', 'returns', 'sales_return', 'sales_returns', 'erp.return_request', 'legacy.return_request' => 'Retur Penjualan',
            'bank_transaction', 'bank_transactions', 'bank_mutation', 'bank_mutations' => 'Mutasi Bank',
            'stock_movement', 'stock_movements' => 'Mutasi Stok',
            default => Str::headline($externalType),
        };
    }

    private function timestampLabel(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '—';
        }

        try {
            return CarbonImmutable::parse($timestamp)
                ->timezone(config('app.timezone'))
                ->format('d M Y H:i');
        } catch (\Throwable) {
            return $timestamp;
        }
    }

    private function payloadPreview(mixed $payload): string
    {
        if (is_string($payload)) {
            $decodedPayload = json_decode($payload, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $decodedPayload = ['payload' => $payload];
            }
        } else {
            $decodedPayload = $payload;
        }

        $preview = json_encode(
            $this->redactSensitivePayloadValues($decodedPayload),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return Str::limit($preview ?: '—', 1_800, "\n…");
    }

    private function redactSensitivePayloadValues(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redactedValue = [];

        foreach ($value as $key => $item) {
            $normalizedKey = Str::lower((string) $key);

            $redactedValue[$key] = Str::contains(
                $normalizedKey,
                ['password', 'secret', 'token', 'authorization', 'api_key', 'access_key'],
            )
                ? '[disamarkan]'
                : $this->redactSensitivePayloadValues($item);
        }

        return $redactedValue;
    }

    /**
     * @param  array{is_global: bool, entity_ids: list<int>, branch_ids_by_entity: array<int, list<int>>}  $accessScope
     */
    private function scopeLabel(?LegalEntity $selectedEntity, array $accessScope): string
    {
        if ($selectedEntity === null) {
            return 'Global · seluruh perusahaan yang terdaftar';
        }

        if ($accessScope['is_global'] || in_array($selectedEntity->id, $accessScope['entity_ids'], true)) {
            return "{$selectedEntity->code} · seluruh cabang dalam scope perusahaan";
        }

        $branchCount = count($accessScope['branch_ids_by_entity'][$selectedEntity->id] ?? []);

        return "{$selectedEntity->code} · {$branchCount} cabang dalam scope akses";
    }
}
