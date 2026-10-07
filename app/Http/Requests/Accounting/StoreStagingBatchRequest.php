<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStagingBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_key' => [
                'required',
                'string',
                Rule::in(array_keys((array) config('accounting.sources'))),
            ],
            'legal_entity_code' => ['required', 'string', 'max:16'],
            'scope' => ['required', 'string', 'max:64'],
            'watermark_before' => ['nullable', 'string', 'max:255'],
            'watermark_after' => ['nullable', 'string', 'max:255'],
            'records' => [
                'required',
                'array',
                'min:1',
                'max:'.((int) config('accounting.staging.maximum_page_size')),
            ],
            'records.*.external_type' => [
                'required',
                'string',
                Rule::in([
                    'customer',
                    'chart_of_account',
                    'sales_invoice',
                    'invoice_payment',
                    'cash_transaction',
                ]),
            ],
            'records.*.external_key' => ['required', 'string', 'max:128'],
            'records.*.branch_code' => ['nullable', 'string', 'max:16'],
            'records.*.external_updated_at' => ['nullable', 'date'],
            'records.*.occurred_at' => ['nullable', 'date'],
            'records.*.payload' => ['required', 'array'],
        ];
    }
}
