<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_access_logs', function (Blueprint $table) {
            // Patients can change consent or revoke an invitation before a provider registers.
            $table->unsignedBigInteger('provider_id')->nullable()->change();
            $table->foreignId('link_id')->nullable()->constrained('provider_client_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('provider_access_logs')->whereNull('provider_id')->exists()) {
            throw new RuntimeException('Resolve invitation audit entries before narrowing provider_id.');
        }
        Schema::table('provider_access_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('link_id');
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
        });
    }
};
