<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['community_reports', 'support_reports'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->timestamp('resolved_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        foreach (['community_reports', 'support_reports'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['resolved_at']);
                $t->dropColumn('resolved_at');
            });
        }
    }
};
