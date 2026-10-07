<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('module', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index('module');
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();

            /*
             * These are deliberately indexed IDs rather than foreign keys. The
             * accounting master-data migration is applied after this migration,
             * so a foreign key would fail on a fresh MySQL database. The model
             * relations still make entity/branch scope explicit.
             */
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('scope_key', 64)->default('global');

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'expires_at'], 'role_assignment_active_index');
            $table->index(['legal_entity_id', 'branch_id'], 'role_assignment_scope_index');
        });

        Schema::create('access_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 96);
            $table->string('subject_type', 128);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'access_audit_subject_index');
            $table->index(['actor_id', 'action', 'created_at'], 'access_audit_actor_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_audits');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
