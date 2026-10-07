<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing installations originally keyed staged records only by source,
     * type, and source identifier. The same source identifier can legitimately
     * exist under BMM and TMP, so convert that identity to include the legal
     * entity without changing any staged payloads.
     *
     * The checks keep this safe for both existing MySQL/SQLite databases and
     * fresh installations, where the original create migration already creates
     * the scoped unique index.
     */
    public function up(): void
    {
        if (! Schema::hasTable('staged_source_records')) {
            return;
        }

        if (Schema::hasIndex('staged_source_records', 'staged_source_record_entity_unique')) {
            return;
        }

        if (Schema::hasIndex('staged_source_records', 'staged_source_record_unique')) {
            Schema::table('staged_source_records', function (Blueprint $table): void {
                $table->dropUnique('staged_source_record_unique');
            });
        }

        Schema::table('staged_source_records', function (Blueprint $table): void {
            $table->unique(
                ['data_source_id', 'legal_entity_id', 'external_type', 'external_key'],
                'staged_source_record_entity_unique',
            );
        });
    }

    /**
     * Do not restore the previous cross-entity unique key on rollback: once
     * BMM and TMP contain the same source key, doing so would either fail or
     * require deleting valid local staging data.
     */
    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
