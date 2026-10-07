<?php

namespace App\Models;

use App\Models\Access\RoleAssignment;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function hasPermission(
        string $permission,
        ?int $legalEntityId = null,
        ?int $branchId = null,
    ): bool {
        return $this->activeRoleAssignments()->contains(
            fn (RoleAssignment $assignment): bool => $assignment->allowsScope($legalEntityId, $branchId)
                && $assignment->role->permissions->contains('code', $permission),
        );
    }

    public function hasGlobalPermission(string $permission): bool
    {
        return $this->activeRoleAssignments()->contains(
            fn (RoleAssignment $assignment): bool => $assignment->scope_key === 'global'
                && $assignment->role->permissions->contains('code', $permission),
        );
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    private function activeRoleAssignments(): Collection
    {
        return $this->roleAssignments()
            ->with('role.permissions')
            ->get()
            ->filter(static fn (RoleAssignment $assignment): bool => $assignment->isActive())
            ->values();
    }
}
