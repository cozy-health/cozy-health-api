<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            // Legacy directory records remain linkable without enabling password login.
            $table->string('password_hash')->nullable();
            $table->string('practice')->nullable();
            $table->string('license_state')->nullable();
            $table->string('timezone')->default('UTC');
            $table->rememberToken();
            $table->unique(['license_number', 'license_state']);
        });

        Schema::create('provider_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
        });

        Schema::create('provider_client_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) {
                $table->boolean('consent_'.$type)->default(false);
            }
            $table->timestamp('linked_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unique(['provider_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
            $table->index(['provider_id', 'revoked_at']);
        });

        Schema::create('provider_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['provider_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('provider_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['provider_id', 'user_id', 'created_at']);
        });

        Schema::create('provider_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
            $table->index('provider_id');
        });

        Schema::create('provider_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('provider_resources')->cascadeOnDelete();
            $table->enum('status', ['assigned', 'completed', 'declined'])->default('assigned');
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->index(['user_id', 'status']);
            $table->index(['provider_id', 'user_id']);
        });

        Schema::create('provider_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('file_path');
            $table->timestamp('generated_at');
            $table->index(['provider_id', 'user_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        foreach (['provider_reports', 'provider_assignments', 'provider_resources', 'provider_notes', 'provider_access_logs', 'provider_client_links', 'provider_password_reset_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('providers', function (Blueprint $table) {
            $table->dropUnique(['license_number', 'license_state']);
            $table->dropColumn(['password_hash', 'practice', 'license_state', 'timezone', 'remember_token']);
        });
    }
};
