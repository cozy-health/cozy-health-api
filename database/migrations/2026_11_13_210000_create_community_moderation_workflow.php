<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('community_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 20);
            $t->uuid('target_id')->nullable();
            $t->string('content_version', 64)->nullable();
            $t->text('reason');
            $t->string('status', 20)->default('active');
            $t->uuid('version');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'kind', 'status']);
        });
        Schema::create('community_appeals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('decision_id')->unique()->constrained('community_decisions')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('message');
            $t->string('status', 20)->default('pending');
            $t->text('outcome')->nullable();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('version');
            $t->timestamps();
        });
        Schema::create('community_moderation_notices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('email_key', 64);
            $t->text('payload');
            $t->string('status', 20)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('retry_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'retry_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('community_moderation_notices');
        Schema::dropIfExists('community_appeals');
        Schema::dropIfExists('community_decisions');
    }
};
