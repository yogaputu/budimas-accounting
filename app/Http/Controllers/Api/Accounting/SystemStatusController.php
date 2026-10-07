<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use Illuminate\Http\JsonResponse;

final class SystemStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $legalEntities = LegalEntity::query()
            ->where('is_active', true)
            ->withCount('branches')
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(static fn (LegalEntity $legalEntity): array => [
                'code' => $legalEntity->code,
                'name' => $legalEntity->name,
                'branch_count' => $legalEntity->branches_count,
            ])
            ->values();

        $sources = DataSource::query()
            ->where('is_active', true)
            ->orderBy('key')
            ->get(['key', 'name', 'kind', 'access_mode'])
            ->map(static fn (DataSource $dataSource): array => [
                'key' => $dataSource->key,
                'name' => $dataSource->name,
                'kind' => $dataSource->kind,
                'access_mode' => $dataSource->access_mode,
            ])
            ->values();

        return response()->json([
            'service' => 'budimas-accounting',
            'status' => 'ok',
            'database_connection' => config('database.default'),
            'source_mode' => 'read_only_sync',
            'entities' => $legalEntities,
            'sources' => $sources,
        ]);
    }
}
