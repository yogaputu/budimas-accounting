<?php

namespace App\Models\Access;

use App\Models\Accounting\Branch;
use App\Models\Accounting\LegalEntity;
use App\Models\User;
use Database\Factories\Access\RoleAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignment extends Model
{
    /** @use HasFactory<RoleAssignmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role_id',
        'legal_entity_id',
        'branch_id',
        'scope_key',
        'assigned_by',
        'assigned_at',
        'revoked_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allowsScope(?int $legalEntityId = null, ?int $branchId = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return match ($this->scope_key) {
            'global' => true,
            'entity', 'legal_entity' => $legalEntityId !== null
                && $this->legal_entity_id === $legalEntityId,
            'branch' => $branchId !== null
                && $this->branch_id === $branchId
                && ($legalEntityId === null || $this->branch()
                    ->where('legal_entity_id', $legalEntityId)
                    ->exists()),
            default => false,
        };
    }
}
