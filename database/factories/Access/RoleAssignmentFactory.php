<?php

namespace Database\Factories\Access;

use App\Models\Access\Role;
use App\Models\Access\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleAssignment>
 */
class RoleAssignmentFactory extends Factory
{
    /**
     * The model that this factory creates.
     *
     * @var class-string<RoleAssignment>
     */
    protected $model = RoleAssignment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'legal_entity_id' => null,
            'branch_id' => null,
            'scope_key' => 'global',
            'assigned_by' => null,
            'assigned_at' => now(),
            'revoked_at' => null,
            'expires_at' => null,
        ];
    }
}
