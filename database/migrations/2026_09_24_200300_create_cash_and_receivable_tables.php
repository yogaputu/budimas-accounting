<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_of_account_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('kind', 24);
            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code']);
        });

        Schema::create('receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('staged_source_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_key', 128);
            $table->string('invoice_no', 64);
            $table->string('customer_code', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('invoice_amount', 20, 2);
            $table->decimal('paid_amount', 20, 2)->default(0);
            $table->decimal('outstanding_amount', 20, 2);
            $table->string('status', 24)->default('open');
            $table->timestamps();
            $table->unique(['data_source_id', 'external_key']);
            $table->index(['legal_entity_id', 'branch_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('cash_accounts');
    }
};
