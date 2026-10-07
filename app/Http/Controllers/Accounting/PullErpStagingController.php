<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\ErpStagingPullException;
use App\Accounting\ErpStagingPullService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PullErpStagingRequest;
use App\Models\Accounting\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PullErpStagingController extends Controller
{
    public function __construct(private readonly ErpStagingPullService $erpStagingPullService) {}

    public function __invoke(PullErpStagingRequest $request): RedirectResponse
    {
        $attributes = $request->validated();
        $lock = Cache::lock('accounting:staging:erp:'.$attributes['entity_code'], 120);

        if (! $lock->get()) {
            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors(['staging' => 'Penarikan ERP untuk perusahaan ini masih berjalan. Tunggu hingga selesai, lalu muat ulang halaman.']);
        }

        try {
            $result = $this->erpStagingPullService->pull(
                entityCode: $attributes['entity_code'],
                dataset: $attributes['dataset'],
                limit: (int) $attributes['limit'],
                actor: $request->user(),
            );
        } catch (ErpStagingPullException $exception) {
            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors(['staging' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors(['staging' => 'Data ERP belum dapat disimpan ke staging. Periksa konfigurasi sumber, perusahaan, dan cabang.']);
        } catch (Throwable $exception) {
            Log::error('Accounting web ERP staging pull failed.', [
                'exception_class' => $exception::class,
                'entity_code' => $attributes['entity_code'],
                'dataset' => $attributes['dataset'],
                'actor_id' => $request->user()->id,
            ]);

            return to_route('accounting.staging.index')
                ->withInput($attributes)
                ->withErrors(['staging' => 'Penarikan data ERP gagal diproses. Silakan coba lagi atau hubungi administrator.']);
        } finally {
            $lock->release();
        }

        $syncRun = SyncRun::query()->findOrFail($result['sync_run_id']);
        $filters = [
            'legal_entity_id' => $syncRun->legal_entity_id,
            'data_source_id' => $syncRun->data_source_id,
        ];

        if ($result['upsert_count'] > 0) {
            $filters['external_type'] = match ($result['dataset']) {
                ErpStagingPullService::DATASET_CUSTOMERS => 'erp.customer',
                ErpStagingPullService::DATASET_SALES_INVOICES => 'erp.sales_invoice',
                ErpStagingPullService::DATASET_PAYMENTS => 'erp.mobile_payment_claim',
                ErpStagingPullService::DATASET_RETURNS => 'erp.return_request',
            };
        }

        return to_route('accounting.staging.index', $filters)
            ->withInput($attributes)
            ->with('staging_pull_result', [...$result, 'entity_code' => $result['entity']]);
    }
}
