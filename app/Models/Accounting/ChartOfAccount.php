<?php

namespace App\Models\Accounting;

use Database\Factories\Accounting\ChartOfAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    /** @use HasFactory<ChartOfAccountFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'asset' => 'Aset',
        'liability' => 'Kewajiban',
        'equity' => 'Ekuitas',
        'revenue' => 'Pendapatan',
        'cost_of_sales' => 'Harga Pokok Penjualan',
        'expense' => 'Beban Operasional',
        'other_income' => 'Pendapatan Lain-lain',
        'other_expense' => 'Beban Lain-lain',
    ];

    /**
     * @var array<string, string>
     */
    public const NORMAL_BALANCE_LABELS = [
        'debit' => 'Debit',
        'credit' => 'Kredit',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legal_entity_id',
        'code',
        'name',
        'category',
        'normal_balance',
        'parent_id',
        'is_postable',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isHeader(): bool
    {
        return ! $this->is_postable;
    }
}
