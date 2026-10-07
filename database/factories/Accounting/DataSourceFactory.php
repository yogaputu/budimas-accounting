<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->company().' Source',
            'kind' => 'postgresql',
            'access_mode' => 'read_only',
            'is_active' => true,
            'settings' => null,
        ];
    }
}
