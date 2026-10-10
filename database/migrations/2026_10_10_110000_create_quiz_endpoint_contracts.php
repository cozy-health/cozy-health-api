<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $t) {
            $t->timestamp('approved_at')->nullable();
            $t->json('scoring_rules')->nullable();
            $t->text('disclaimer')->nullable();
        });
        Schema::create('quiz_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('quiz_id')->constrained()->restrictOnDelete();
            $t->json('definition');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('quiz_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $t->foreignId('link_id')->constrained('provider_client_links')->cascadeOnDelete();
            $t->timestamp('consented_at');
            $t->timestamps();
            $t->unique(['quiz_attempt_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_shares');
        Schema::dropIfExists('quiz_sessions');
        Schema::table('quizzes', fn (Blueprint $t) => $t->dropColumn(['approved_at', 'scoring_rules', 'disclaimer']));
    }
};
