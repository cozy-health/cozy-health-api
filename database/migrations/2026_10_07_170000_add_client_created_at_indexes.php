<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mood_entries', function (Blueprint $table) {
            $table->index(['user_id', 'client_created_at'], 'mood_entries_user_id_client_created_at_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['user_id', 'client_created_at'], 'journal_entries_user_id_client_created_at_idx');
        });

        Schema::table('app_notifications', function (Blueprint $table) {
            $table->index(['user_id', 'client_created_at'], 'app_notifications_user_id_client_created_at_idx');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->index(['user_id', 'client_created_at'], 'quiz_attempts_user_id_client_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex('quiz_attempts_user_id_client_created_at_idx');
        });

        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex('app_notifications_user_id_client_created_at_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_user_id_client_created_at_idx');
        });

        Schema::table('mood_entries', function (Blueprint $table) {
            $table->dropIndex('mood_entries_user_id_client_created_at_idx');
        });
    }
};
