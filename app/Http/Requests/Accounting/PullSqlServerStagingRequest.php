<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\LegacySqlServerStagingPullService;
use App\Models\Accounting\Branch;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PullSqlServerStagingRequest extends FormRequest
{
    protected $redirectRoute = 'accounting.staging.index';

    protected $errorBag = 'sqlserverPull';

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
            'source_key' => ['bail', 'required', 'string', Rule::in(array_keys(LegacySqlServerStagingPullService::SOURCES))],
            'dataset' => ['required', 'string', Rule::in(array_keys(LegacySqlServerStagingPullService::DATASETS))],
            'limit' => ['required', 'integer', 'between:1,100'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('source_key')) {
                return;
            }

            $source = LegacySqlServerStagingPullService::SOURCES[$this->input('source_key')];
            $entity = LegalEntity::query()
                ->where('code', $source['entity_code'])
                ->where('is_active', true)
                ->first();

            if ($entity === null || ! Branch::query()
                ->where('legal_entity_id', $entity->id)
                ->where('code', $source['branch_code'])
                ->where('is_active', true)
                ->exists()) {
                $validator->errors()->add('source_key', 'Perusahaan dan cabang sumber harus tersedia dan aktif di Accounting.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_key.required' => 'Pilih sumber SQL Server.',
            'source_key.string' => 'Sumber SQL Server tidak valid.',
            'source_key.in' => 'Sumber SQL Server tidak valid.',
            'dataset.required' => 'Pilih jenis data SQL Server.',
            'dataset.string' => 'Jenis data SQL Server tidak valid.',
            'dataset.in' => 'Jenis data SQL Server tidak valid.',
            'limit.required' => 'Isi batas jumlah data yang akan ditarik.',
            'limit.integer' => 'Batas data harus berupa angka bulat antara 1 dan 100.',
            'limit.between' => 'Batas data harus antara 1 dan 100.',
        ];
    }
}
