<?php

namespace App\Models\Accounting;

use Database\Factories\Accounting\DataSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'kind',
        'access_mode',
        'is_active',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function stagedSourceRecords(): HasMany
    {
        return $this->hasMany(StagedSourceRecord::class);
    }
}
