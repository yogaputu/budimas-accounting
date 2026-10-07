<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\ErpStagingPullService;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PullErpStagingRequest extends FormRequest
{
    protected $redirectRoute = 'accounting.staging.index';

    public function authorize(): bool
    {
        return ($this->user()?->hasGlobalPermission(PermissionCode::SyncManage->value) ?? false)
            && ($this->user()?->hasGlobalPermission(PermissionCode::SystemHealthView->value) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_code' => [
                'bail', 'required', 'string', Rule::in(['BMM', 'TMP']),
                Rule::exists(LegalEntity::class, 'code')->where('is_active', true),
            ],
            'dataset' => ['required', 'string', Rule::in([
                ErpStagingPullService::DATASET_CUSTOMERS,
                ErpStagingPullService::DATASET_SALES_INVOICES,
                ErpStagingPullService::DATASET_PAYMENTS,
                ErpStagingPullService::DATASET_RETURNS,
            ])],
            'limit' => ['required', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'entity_code.required' => 'Pilih perusahaan yang akan ditarik datanya.',
            'entity_code.string' => 'Perusahaan harus BMM atau TMP.',
            'entity_code.in' => 'Perusahaan harus BMM atau TMP.',
            'entity_code.exists' => 'Perusahaan tidak ditemukan atau tidak aktif.',
            'dataset.required' => 'Pilih jenis data ERP.',
            'dataset.string' => 'Jenis data ERP tidak valid.',
            'dataset.in' => 'Jenis data ERP tidak valid.',
            'limit.required' => 'Isi batas jumlah data yang akan ditarik.',
            'limit.integer' => 'Batas data harus berupa angka bulat antara 1 dan 100.',
            'limit.between' => 'Batas data harus antara 1 dan 100.',
        ];
    }
}
