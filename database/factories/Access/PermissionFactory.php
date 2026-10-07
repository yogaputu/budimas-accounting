<?php

namespace Database\Factories\Access;

use App\Models\Access\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * The model that this factory creates.
     *
     * @var class-string<Permission>
     */
    protected $model = Permission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $module = fake()->unique()->word();

        return [
            'code' => $module.'.manage',
            'module' => ucfirst($module),
            'name' => ucfirst($module).' manage',
            'description' => fake()->sentence(),
        ];
    }
}
