<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_document_revisions', function (Blueprint $table) {
            $table->id(); $table->string('audience', 20); $table->string('document_type', 80); $table->string('version', 80);
            $table->text('content'); $table->string('sha256', 64); $table->timestamp('published_at');
            $table->unique(['audience', 'document_type', 'version']);
        });
        Schema::create('student_applications', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('institution', 200); $table->text('institution_email')->nullable();
            $table->string('email_token_hash', 64)->nullable(); $table->timestamp('email_token_expires_at')->nullable(); $table->timestamp('email_verified_at')->nullable();
            $table->text('evidence_path')->nullable(); $table->timestamp('evidence_delete_at')->nullable();
            $table->string('status', 20)->default('pending'); $table->text('review_note')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable(); $table->timestamp('eligible_until')->nullable(); $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('student_review_events', function (Blueprint $table) {
            $table->id(); $table->foreignUuid('application_id')->constrained('student_applications')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20); $table->text('note'); $table->timestamp('created_at');
        });
        Schema::create('store_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete(); $table->uuid('account_token')->unique();
        });
        Schema::create('store_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10); $table->string('purchase_hash', 64)->unique();
            $table->text('purchase_reference'); $table->string('product_id', 200); $table->string('tier', 20);
            $table->string('status', 30); $table->timestamp('expires_at')->nullable(); $table->timestamp('verified_at'); $table->timestamps();
        });
        Schema::create('emergency_email_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('email'); $table->string('token_hash', 64); $table->string('unsubscribe_hash', 64); $table->text('unsubscribe_token');
            $table->timestamp('token_expires_at'); $table->timestamp('confirmed_at')->nullable(); $table->timestamps();
        });
        Schema::create('emergency_email_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('emergency_email_contacts')->cascadeOnDelete();
            $table->uuid('request_id'); $table->string('status', 30)->default('queued'); $table->timestamps();
            $table->unique(['user_id', 'request_id']); $table->index(['user_id', 'created_at']);
        });
        Schema::create('emergency_email_daily_quotas', function (Blueprint $table) {
            $table->date('day')->primary(); $table->unsignedInteger('reserved')->default(0);
        });
    }
    public function down(): void
    {
        foreach (['emergency_email_alerts', 'emergency_email_contacts', 'emergency_email_daily_quotas', 'store_purchases', 'store_accounts', 'student_review_events', 'student_applications', 'legal_document_revisions'] as $table) Schema::dropIfExists($table);
    }
};
