<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_statuses', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'subscription_statuses_user_id_status_idx');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->index(['user_id', 'completed_at'], 'quiz_attempts_user_id_completed_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex('quiz_attempts_user_id_completed_at_idx');
        });

        Schema::table('subscription_statuses', function (Blueprint $table) {
            $table->dropIndex('subscription_statuses_user_id_status_idx');
        });
    }
};
