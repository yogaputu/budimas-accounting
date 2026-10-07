<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Accounting\StagingBatchIngestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreStagingBatchRequest;
use Illuminate\Http\JsonResponse;

final class StagingBatchController extends Controller
{
    public function __invoke(
        StoreStagingBatchRequest $request,
        StagingBatchIngestService $stagingBatchIngestService,
    ): JsonResponse {
        $result = $stagingBatchIngestService->ingest($request->validated());

        return response()->json([
            'message' => 'Snapshot read-only berhasil disimpan ke staging Accounting.',
            'read_count' => $result['read_count'],
            'sync_run_id' => $result['sync_run']->id,
            'upsert_count' => $result['upsert_count'],
        ], 201);
    }
}
