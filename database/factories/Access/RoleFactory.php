<?php

namespace Database\Factories\Access;

use App\Models\Access\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * The model that this factory creates.
     *
     * @var class-string<Role>
     */
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'role_'.fake()->unique()->numerify('###'),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }
}
