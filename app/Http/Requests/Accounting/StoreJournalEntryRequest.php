<?php

namespace App\Http\Requests\Accounting;

use App\Models\Accounting\Branch;
use App\Models\Accounting\LegalEntity;
use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branchId = $this->filled('branch_id') ? $this->integer('branch_id') : null;

        return $this->user()?->hasPermission(
            PermissionCode::JournalManage->value,
            $this->integer('legal_entity_id'),
            $branchId,
        ) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'legal_entity_id' => ['required', 'integer', Rule::exists(LegalEntity::class, 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists(Branch::class, 'id')],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.chart_of_account_id' => ['required', 'integer'],
            'lines.*.branch_id' => ['nullable', 'integer', Rule::exists(Branch::class, 'id')],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => trim((string) $this->input('description')),
        ]);
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $totalDebit = '0.00';
            $totalCredit = '0.00';

            foreach ($this->input('lines', []) as $index => $line) {
                $debit = bcadd((string) ($line['debit'] ?? '0'), '0', 2);
                $credit = bcadd((string) ($line['credit'] ?? '0'), '0', 2);
                $debitIsPositive = bccomp($debit, '0.00', 2) === 1;
                $creditIsPositive = bccomp($credit, '0.00', 2) === 1;

                if ($debitIsPositive === $creditIsPositive) {
                    $validator->errors()->add(
                        "lines.{$index}.debit",
                        'Setiap baris harus memiliki debit atau kredit positif, bukan keduanya.',
                    );
                }

                $totalDebit = bcadd($totalDebit, $debit, 2);
                $totalCredit = bcadd($totalCredit, $credit, 2);
            }

            if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                $validator->errors()->add('lines', 'Total debit dan kredit harus seimbang sampai dua angka desimal.');
            }
        }];
    }
}
