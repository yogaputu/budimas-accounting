<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure the snapshot references after the master and staging tables are
     * present. This is intentionally idempotent so it is safe for both fresh
     * SQLite installs and existing MySQL databases where the earlier migration
     * may already have added the columns.
     */
    public function up(): void
    {
        if (Schema::hasTable('sync_runs') && ! Schema::hasColumn('sync_runs', 'initiated_by')) {
            Schema::table('sync_runs', function (Blueprint $table): void {
                $table->foreignId('initiated_by')
                    ->nullable()
                    ->after('legal_entity_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('staged_source_records') && ! Schema::hasColumn('staged_source_records', 'sync_run_id')) {
            Schema::table('staged_source_records', function (Blueprint $table): void {
                $table->foreignId('sync_run_id')
                    ->nullable()
                    ->after('data_source_id')
                    ->constrained('sync_runs')
                    ->nullOnDelete();
            });
        }

        if (
            Schema::hasTable('staged_source_records')
            && ! Schema::hasIndex('staged_source_records', 'staged_records_sync_run_type_idx')
        ) {
            Schema::table('staged_source_records', function (Blueprint $table): void {
                $table->index(['sync_run_id', 'external_type'], 'staged_records_sync_run_type_idx');
            });
        }

        if (
            Schema::hasTable('staged_source_records')
            && ! Schema::hasIndex('staged_source_records', 'staged_records_source_type_synced_idx')
        ) {
            Schema::table('staged_source_records', function (Blueprint $table): void {
                $table->index(
                    ['data_source_id', 'external_type', 'synced_at'],
                    'staged_records_source_type_synced_idx',
                );
            });
        }
    }

    /**
     * The referenced columns belong to the original snapshot migration. This
     * compatibility migration therefore leaves them in place on rollback,
     * avoiding removal of schema that could predate this correction.
     */
    public function down(): void
    {
        // Intentionally non-destructive; see the docblock above.
    }
};
