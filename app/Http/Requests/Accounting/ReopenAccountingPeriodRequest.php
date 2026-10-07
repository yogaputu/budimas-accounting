<?php

namespace App\Http\Requests\Accounting;

use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class ReopenAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasGlobalPermission(PermissionCode::PeriodManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
