<?php

namespace Database\Factories\Access;

use App\Models\Access\AccessAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessAudit>
 */
class AccessAuditFactory extends Factory
{
    /**
     * The model that this factory creates.
     *
     * @var class-string<AccessAudit>
     */
    protected $model = AccessAudit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'action' => 'access.updated',
            'subject_type' => 'user',
            'subject_id' => fake()->numberBetween(1, 10_000),
            'old_values' => ['role_ids' => []],
            'new_values' => ['role_ids' => []],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
