<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('body');
            $table->index(['user_id', 'is_draft'], 'journal_entries_user_id_is_draft_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_user_id_is_draft_idx');
            $table->dropColumn('is_draft');
        });
    }
};
