<?php

namespace Database\Seeders\Accounting;

use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class AccountingFoundationSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();

        $legalEntities = collect(config('accounting.entities'))
            ->map(static fn (string $name, string $code): array => [
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->values()
            ->all();

        LegalEntity::query()->upsert(
            $legalEntities,
            ['code'],
            ['name', 'is_active', 'updated_at'],
        );

        $dataSources = collect(config('accounting.sources'))
            ->map(static fn (array $source, string $key): array => [
                'key' => $key,
                'name' => $source['label'],
                'kind' => $source['kind'],
                'access_mode' => $source['mode'],
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->values()
            ->all();

        DataSource::query()->upsert(
            $dataSources,
            ['key'],
            ['name', 'kind', 'access_mode', 'is_active', 'updated_at'],
        );
    }
}
