<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code']);
        });

        Schema::create('data_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('kind', 32);
            $table->string('access_mode', 32)->default('read_only');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 64)->default('full');
            $table->string('status', 24)->default('queued');
            $table->string('watermark_before')->nullable();
            $table->string('watermark_after')->nullable();
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('upsert_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['data_source_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('data_sources');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('legal_entities');
    }
};
