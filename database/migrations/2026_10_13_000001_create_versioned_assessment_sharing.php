<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quiz_definitions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $t->string('fingerprint', 64);
            $t->json('definition');
            $t->timestamp('approved_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['quiz_id', 'fingerprint']);
        });
        Schema::table('quiz_attempts', function (Blueprint $t) {
            $t->foreignUuid('definition_version')->nullable()->constrained('quiz_definitions')->restrictOnDelete();
            $t->json('result_snapshot')->nullable();
            $t->unsignedSmallInteger('maximum_score')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->timestamp('disclaimer_accepted_at')->nullable();
        });
        Schema::table('quiz_shares', function (Blueprint $t) {
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
        });
        Schema::create('quiz_friend_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamps();
            $t->unique(['quiz_attempt_id', 'recipient_id']);
            $t->index(['recipient_id', 'created_at']);
        });
        Schema::create('quiz_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('recipient_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });
        Schema::table('provider_reports', fn (Blueprint $t) => $t->json('quiz_share_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('provider_reports', fn (Blueprint $t) => $t->dropColumn('quiz_share_snapshot'));
        Schema::dropIfExists('quiz_invitations');
        Schema::dropIfExists('quiz_friend_shares');
        Schema::table('quiz_shares', fn (Blueprint $t) => $t->dropColumn(['revoked_at', 'read_at', 'revision']));
        Schema::table('quiz_attempts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('definition_version');
            $t->dropColumn(['result_snapshot', 'maximum_score', 'validated_at', 'disclaimer_accepted_at']);
        });
        Schema::dropIfExists('quiz_definitions');
    }
};
