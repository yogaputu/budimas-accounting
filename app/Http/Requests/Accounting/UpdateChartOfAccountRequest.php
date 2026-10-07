<?php

namespace App\Http\Requests\Accounting;

use App\Models\Accounting\ChartOfAccount;
use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasGlobalPermission(PermissionCode::JournalManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $account = $this->account();

        return [
            'legal_entity_id' => ['required', 'integer', Rule::in([$account->legal_entity_id])],
            'code' => [
                'required',
                'string',
                'max:32',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique(ChartOfAccount::class, 'code')
                    ->where(fn ($query) => $query->where('legal_entity_id', $account->legal_entity_id))
                    ->ignore($account),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(array_keys(ChartOfAccount::CATEGORY_LABELS))],
            'normal_balance' => ['required', 'string', Rule::in(array_keys(ChartOfAccount::NORMAL_BALANCE_LABELS))],
            'parent_id' => ['nullable', 'integer', Rule::exists(ChartOfAccount::class, 'id')],
            'is_postable' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'category' => strtolower(trim((string) $this->input('category'))),
            'normal_balance' => strtolower(trim((string) $this->input('normal_balance'))),
            'is_postable' => $this->boolean('is_postable'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : false,
        ]);
    }

    public function after(): array
    {
        return [function ($validator): void {
            $account = $this->account();

            if ($this->filled('parent_id')) {
                $parent = ChartOfAccount::query()->find($this->integer('parent_id'));

                if ($parent !== null && $parent->legal_entity_id !== $account->legal_entity_id) {
                    $validator->errors()->add('parent_id', 'Induk akun harus berasal dari perusahaan yang sama.');
                }

                if ($parent !== null && $parent->is_postable) {
                    $validator->errors()->add('parent_id', 'Induk akun harus berupa akun kelompok, bukan akun posting.');
                }

                if ($parent !== null && $parent->category !== $this->input('category')) {
                    $validator->errors()->add('parent_id', 'Kategori akun harus sama dengan kategori induknya.');
                }

                if ($this->createsParentCycle($account, $parent)) {
                    $validator->errors()->add('parent_id', 'Induk akun tidak boleh berupa akun ini atau turunan akun ini.');
                }
            }

            if ($account->children()->exists() && $account->category !== $this->input('category')) {
                $validator->errors()->add('category', 'Kategori akun kelompok tidak dapat diubah selama masih memiliki akun turunan.');
            }
        }];
    }

    private function account(): ChartOfAccount
    {
        /** @var ChartOfAccount $account */
        $account = $this->route('chartOfAccount');

        return $account;
    }

    private function createsParentCycle(ChartOfAccount $account, ?ChartOfAccount $parent): bool
    {
        while ($parent !== null) {
            if ($parent->is($account)) {
                return true;
            }

            $parent = $parent->parent;
        }

        return false;
    }
}
