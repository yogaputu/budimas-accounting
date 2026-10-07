<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\LegacySqlServerStagingPullException;
use App\Accounting\LegacySqlServerStagingPullService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PullSqlServerStagingRequest;
use App\Models\Accounting\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PullSqlServerStagingController extends Controller
{
    public function __construct(private readonly LegacySqlServerStagingPullService $stagingPullService) {}

    public function __invoke(PullSqlServerStagingRequest $request): RedirectResponse
    {
        $attributes = $request->validated();
        $lock = Cache::lock('accounting:staging:sqlserver:'.$attributes['source_key'], 120);

        if (! $lock->get()) {
            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors([
                    'staging' => 'Penarikan SQL Server untuk sumber ini masih berjalan. Tunggu hingga selesai, lalu muat ulang halaman.',
                ], 'sqlserverPull');
        }

        try {
            $result = $this->stagingPullService->pull(
                sourceKey: $attributes['source_key'],
                dataset: $attributes['dataset'],
                limit: (int) $attributes['limit'],
                actor: $request->user(),
            );

            $syncRun = SyncRun::query()->findOrFail($result['sync_run_id']);
            $filters = [
                'legal_entity_id' => $syncRun->legal_entity_id,
                'data_source_id' => $syncRun->data_source_id,
            ];

            if ($result['upsert_count'] > 0) {
                $filters['external_type'] = LegacySqlServerStagingPullService::EXTERNAL_TYPES[$result['dataset']];
            }

            return to_route('accounting.staging.index', $filters)
                ->withInput($attributes)
                ->with('sqlserver_pull_result', $result);
        } catch (LegacySqlServerStagingPullException $exception) {
            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors(['staging' => $exception->getMessage()], 'sqlserverPull');
        } catch (Throwable $exception) {
            Log::error('Accounting web SQL Server staging pull failed.', [
                'exception_class' => $exception::class,
                'scope' => 'sqlserver:'.$attributes['source_key'].':'.$attributes['dataset'],
            ]);

            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors([
                    'staging' => 'Penarikan data SQL Server gagal diproses. Silakan coba lagi atau hubungi administrator.',
                ], 'sqlserverPull');
        } finally {
            $lock->release();
        }
    }
}
