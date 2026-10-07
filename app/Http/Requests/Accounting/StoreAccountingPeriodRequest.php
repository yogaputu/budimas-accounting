<?php

namespace App\Http\Requests\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasGlobalPermission(PermissionCode::PeriodManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'legal_entity_id' => ['required', 'integer', Rule::exists(LegalEntity::class, 'id')],
            'code' => [
                'required',
                'string',
                'max:16',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique(AccountingPeriod::class, 'code')->where(
                    fn ($query) => $query->where('legal_entity_id', $this->integer('legal_entity_id')),
                ),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
        ]);
    }

    public function after(): array
    {
        return [function ($validator): void {
            $legalEntity = LegalEntity::query()->find($this->integer('legal_entity_id'));

            if ($legalEntity !== null && ! $legalEntity->is_active) {
                $validator->errors()->add('legal_entity_id', 'Perusahaan tidak aktif tidak dapat dipakai untuk periode baru.');
            }

            if ($validator->errors()->has('starts_on') || $validator->errors()->has('ends_on')) {
                return;
            }

            $overlapsExistingPeriod = AccountingPeriod::query()
                ->where('legal_entity_id', $this->integer('legal_entity_id'))
                ->whereDate('starts_on', '<=', (string) $this->input('ends_on'))
                ->whereDate('ends_on', '>=', (string) $this->input('starts_on'))
                ->exists();

            if ($overlapsExistingPeriod) {
                $validator->errors()->add('starts_on', 'Rentang tanggal periode tidak boleh tumpang tindih dengan periode yang sudah ada.');
            }
        }];
    }
}
