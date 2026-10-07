<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntity>
 */
class LegalEntityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('ENT-###'),
            'name' => fake()->company(),
            'is_active' => true,
        ];
    }
}
