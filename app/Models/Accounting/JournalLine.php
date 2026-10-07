<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalLine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'journal_entry_id',
        'chart_of_account_id',
        'branch_id',
        'line_no',
        'memo',
        'debit',
        'credit',
        'dimension_type',
        'dimension_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $journalLine): void {
            $journalLine->ensureEntryIsMutable();
        });

        static::updating(function (self $journalLine): void {
            $journalLine->ensureEntryIsMutable();
        });

        static::deleting(function (self $journalLine): void {
            $journalLine->ensureEntryIsMutable();
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    private function ensureEntryIsMutable(): void
    {
        $entryIds = array_filter([
            $this->journal_entry_id,
            $this->getOriginal('journal_entry_id'),
        ]);

        $hasPostedEntry = JournalEntry::query()
            ->whereKey($entryIds)
            ->where('status', JournalEntry::STATUS_POSTED)
            ->exists();

        if ($hasPostedEntry) {
            throw new LogicException('Baris jurnal yang telah diposting tidak dapat diubah.');
        }
    }
}
