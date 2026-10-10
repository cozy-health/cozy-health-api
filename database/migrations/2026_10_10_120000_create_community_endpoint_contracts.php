<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description');
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });
        Schema::create('community_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'group_id']);
        });
        Schema::create('community_blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'blocked_user_id']);
            $t->index(['blocked_user_id', 'user_id']);
        });
        Schema::create('community_posts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('group_id')->nullable()->constrained('community_groups')->cascadeOnDelete();
            $t->text('body');
            $t->string('mood', 50)->nullable();
            $t->boolean('is_anonymous')->default(false);
            $t->string('status', 20)->default('published');
            $t->string('guidelines_version', 50);
            $t->timestamp('guidelines_accepted_at');
            $t->boolean('is_crisis_flagged')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'created_at']);
            $t->index(['group_id', 'status', 'created_at']);
        });
        Schema::create('community_comments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('post_id')->constrained('community_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('parent_id')->nullable();
            $t->text('body');
            $t->boolean('is_anonymous')->default(false);
            $t->string('status', 20)->default('published');
            $t->boolean('is_crisis_flagged')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['post_id', 'created_at']);
        });
        Schema::create('community_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('target_type', 20);
            $t->uuid('target_id');
            $t->string('reason', 50);
            $t->text('details')->nullable();
            $t->json('snapshot');
            $t->string('status', 20)->default('pending');
            $t->timestamp('due_at');
            $t->timestamps();
            $t->index(['status', 'due_at']);
            $t->unique(['reporter_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        foreach (['community_reports', 'community_comments', 'community_posts', 'community_blocks', 'community_memberships', 'community_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
