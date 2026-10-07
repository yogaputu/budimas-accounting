<?php

namespace App\Http\Requests\Accounting;

use App\Models\Accounting\JournalEntry;
use App\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;

final class PostJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $journalEntry = $this->route('journalEntry');

        return $journalEntry instanceof JournalEntry
            && ($this->user()?->hasPermission(
                PermissionCode::JournalManage->value,
                $journalEntry->legal_entity_id,
                $journalEntry->branch_id,
            ) ?? false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
