<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * This migration was introduced before the migrations that create the
         * staging tables. On a fresh install it must therefore be a safe no-op;
         * the follow-up compatibility migration, placed after those tables,
         * applies the schema change. Existing installations that already have
         * the tables still receive the same additions here.
         */
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

    public function down(): void
    {
        if (Schema::hasTable('staged_source_records')) {
            if (Schema::hasIndex('staged_source_records', 'staged_records_sync_run_type_idx')) {
                Schema::table('staged_source_records', function (Blueprint $table): void {
                    $table->dropIndex('staged_records_sync_run_type_idx');
                });
            }

            if (Schema::hasIndex('staged_source_records', 'staged_records_source_type_synced_idx')) {
                Schema::table('staged_source_records', function (Blueprint $table): void {
                    $table->dropIndex('staged_records_source_type_synced_idx');
                });
            }

            if (Schema::hasColumn('staged_source_records', 'sync_run_id')) {
                $hasForeignKey = Schema::hasForeignKey('staged_source_records', ['sync_run_id']);

                Schema::table('staged_source_records', function (Blueprint $table) use ($hasForeignKey): void {
                    if ($hasForeignKey) {
                        $table->dropForeign(['sync_run_id']);
                    }

                    $table->dropColumn('sync_run_id');
                });
            }
        }

        if (Schema::hasTable('sync_runs') && Schema::hasColumn('sync_runs', 'initiated_by')) {
            $hasForeignKey = Schema::hasForeignKey('sync_runs', ['initiated_by']);

            Schema::table('sync_runs', function (Blueprint $table) use ($hasForeignKey): void {
                if ($hasForeignKey) {
                    $table->dropForeign(['initiated_by']);
                }

                $table->dropColumn('initiated_by');
            });
        }
    }
};
