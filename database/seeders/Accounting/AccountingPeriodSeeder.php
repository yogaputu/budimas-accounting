<?php

namespace Database\Seeders\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class AccountingPeriodSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $year = now()->year;

        foreach (config('accounting.entities') as $legalEntityCode => $legalEntityName) {
            $legalEntity = LegalEntity::query()->firstOrCreate(
                ['code' => $legalEntityCode],
                [
                    'name' => $legalEntityName,
                    'is_active' => true,
                ],
            );

            foreach (range(1, 12) as $month) {
                $startsOn = CarbonImmutable::create($year, $month, 1);

                AccountingPeriod::query()->firstOrCreate(
                    [
                        'legal_entity_id' => $legalEntity->id,
                        'code' => $startsOn->format('Y-m'),
                    ],
                    [
                        'starts_on' => $startsOn->toDateString(),
                        'ends_on' => $startsOn->endOfMonth()->toDateString(),
                        'status' => 'open',
                    ],
                );
            }
        }
    }
}
