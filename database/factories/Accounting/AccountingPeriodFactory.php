<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-18 months', '+6 months')->modify('first day of this month');
        $endsOn = (clone $startsOn)->modify('last day of this month');

        return [
            'legal_entity_id' => LegalEntity::factory(),
            'code' => fake()->unique()->bothify('PER-####'),
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => $endsOn->format('Y-m-d'),
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ];
    }
}
