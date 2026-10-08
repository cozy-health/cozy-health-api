<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_reports', function (Blueprint $table) {
            // Existing reports have no provable consent snapshot and cannot be downloaded.
            $table->json('consent_snapshot')->nullable();
            $table->foreignId('link_id')->nullable()->constrained('provider_client_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('link_id');
            $table->dropColumn('consent_snapshot');
        });
    }
};
