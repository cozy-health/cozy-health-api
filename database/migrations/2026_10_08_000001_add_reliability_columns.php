<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = ['users', 'mood_entries', 'journal_entries', 'chat_messages', 'chat_conversations', 'app_notifications', 'quiz_attempts', 'saved_articles', 'safety_plans'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->softDeletes());
        }
        Schema::table('users', fn (Blueprint $table) => $table->enum('role', ['user', 'admin', 'moderator'])->default('user'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
        foreach ($this->tables as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
