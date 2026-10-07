<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\Branch;
use App\Models\Accounting\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory(),
            'code' => fake()->unique()->bothify('BR-###'),
            'name' => fake()->city().' Branch',
            'is_active' => true,
        ];
    }
}
