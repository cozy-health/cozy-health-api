<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('residence_country', 2)->nullable();
            $table->timestamp('age_declared_at')->nullable();
            $table->boolean('age_disputed')->default(false);
        });
        Schema::create('guardian_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('guardian_name')->nullable();
            $table->text('guardian_email')->nullable();
            $table->string('email_hash', 64)->index();
            $table->string('relationship', 40);
            $table->string('status', 40)->default('pending');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->string('session_hash', 64)->nullable()->unique();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('email_confirmed_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('erase_at')->nullable()->index();
            $table->json('document_versions')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('age_authorization_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('authorization_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->text('details')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('age_support_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('status', 20)->default('open');
            $table->text('message');
            $table->text('resolution')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_support_cases');
        Schema::dropIfExists('age_authorization_events');
        Schema::dropIfExists('guardian_authorizations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['residence_country', 'age_declared_at', 'age_disputed']));
    }
};
