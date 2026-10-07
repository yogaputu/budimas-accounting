<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class JournalEntry extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legal_entity_id',
        'branch_id',
        'accounting_period_id',
        'staged_source_record_id',
        'entry_no',
        'entry_date',
        'source_type',
        'status',
        'description',
        'total_debit',
        'total_credit',
        'posted_by',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $journalEntry): void {
            if ($journalEntry->wasPosted()) {
                throw new LogicException('Jurnal yang telah diposting tidak dapat diubah.');
            }
        });

        static::deleting(function (self $journalEntry): void {
            if ($journalEntry->status === self::STATUS_POSTED || $journalEntry->wasPosted()) {
                throw new LogicException('Jurnal yang telah diposting tidak dapat dihapus.');
            }
        });
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debit, (string) $this->total_credit, 2) === 0;
    }

    private function wasPosted(): bool
    {
        return $this->getOriginal('status') === self::STATUS_POSTED
            || self::query()->whereKey($this->getKey())->value('status') === self::STATUS_POSTED;
    }
}
