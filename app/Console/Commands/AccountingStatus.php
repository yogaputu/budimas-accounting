<?php

namespace App\Console\Commands;

use App\Models\Accounting\DataSource;
use App\Models\Accounting\LegalEntity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('accounting:status')]
#[Description('Show the local Accounting foundation status')]
final class AccountingStatus extends Command
{
    public function handle(): int
    {
        if (! Schema::hasTable('legal_entities') || ! Schema::hasTable('data_sources')) {
            $this->components->error('Accounting tables are not ready. Run php artisan migrate --seed first.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Connection', (string) config('database.default'));
        $this->components->twoColumnDetail('Source policy', 'Read-only sync only');

        $this->newLine();
        $this->table(
            ['Code', 'Legal entity', 'Branches'],
            LegalEntity::query()
                ->withCount('branches')
                ->orderBy('code')
                ->get()
                ->map(static fn (LegalEntity $legalEntity): array => [
                    $legalEntity->code,
                    $legalEntity->name,
                    (string) $legalEntity->branches_count,
                ])
                ->all(),
        );

        $this->table(
            ['Key', 'Source', 'Mode'],
            DataSource::query()
                ->orderBy('key')
                ->get(['key', 'name', 'access_mode'])
                ->map(static fn (DataSource $dataSource): array => [
                    $dataSource->key,
                    $dataSource->name,
                    $dataSource->access_mode,
                ])
                ->all(),
        );

        return self::SUCCESS;
    }
}
