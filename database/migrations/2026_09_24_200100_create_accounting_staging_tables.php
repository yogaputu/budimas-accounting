<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staged_source_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_type', 64);
            $table->string('external_key', 128);
            $table->string('external_updated_at')->nullable();
            $table->string('content_hash', 64);
            $table->json('payload');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            // A source-side identifier is only unique within its legal entity.
            // ERP identifiers may be reused by BMM and TMP, so the entity must
            // participate in the local staging identity from a fresh install.
            $table->unique(
                ['data_source_id', 'legal_entity_id', 'external_type', 'external_key'],
                'staged_source_record_entity_unique',
            );
            $table->index(
                ['legal_entity_id', 'branch_id', 'external_type'],
                'staged_records_scope_type_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staged_source_records');
    }
};
