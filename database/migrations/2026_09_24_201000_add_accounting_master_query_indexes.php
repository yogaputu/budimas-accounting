<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->index(
                ['legal_entity_id', 'category', 'is_active', 'code'],
                'coa_entity_category_active_code_idx',
            );
            $table->index(['parent_id', 'is_active'], 'coa_parent_active_idx');
        });

        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->index(
                ['legal_entity_id', 'starts_on', 'ends_on'],
                'accounting_period_entity_date_idx',
            );
            $table->index(['legal_entity_id', 'status', 'ends_on'], 'accounting_period_entity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->dropIndex('accounting_period_entity_date_idx');
            $table->dropIndex('accounting_period_entity_status_idx');
        });

        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->dropIndex('coa_entity_category_active_code_idx');
            $table->dropIndex('coa_parent_active_idx');
        });
    }
};
