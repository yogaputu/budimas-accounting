<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 24);
            $table->string('normal_balance', 6);
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code']);
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('staged_source_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_no', 48);
            $table->date('entry_date');
            $table->string('source_type', 48)->default('manual');
            $table->string('status', 24)->default('draft');
            $table->string('description')->nullable();
            $table->decimal('total_debit', 20, 2)->default(0);
            $table->decimal('total_credit', 20, 2)->default(0);
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'entry_no']);
            $table->index(['legal_entity_id', 'entry_date', 'status']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('memo')->nullable();
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->string('dimension_type', 32)->nullable();
            $table->string('dimension_key', 128)->nullable();
            $table->timestamps();
            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['chart_of_account_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('chart_of_accounts');
    }
};
