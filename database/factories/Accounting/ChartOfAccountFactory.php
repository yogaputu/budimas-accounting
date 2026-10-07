<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'legal_entity_id' => LegalEntity::factory(),
            'code' => fake()->unique()->numerify('9###'),
            'name' => fake()->words(3, true),
            'category' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => null,
            'is_postable' => true,
            'is_active' => true,
        ];
    }
}
