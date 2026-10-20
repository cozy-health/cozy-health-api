<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->string('provider_name', 191)->nullable()->after('provider_email');
            $table->string('provider_phone', 32)->nullable()->after('provider_name');
            $table->string('verification_code', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Refuse a rollback that would truncate a pending verification hash.
        if (DB::table('provider_connections')->whereRaw('LENGTH(verification_code) > 10')->exists()) {
            throw new RuntimeException('Cannot narrow verification_code while values longer than 10 characters exist.');
        }

        Schema::table('provider_connections', function (Blueprint $table) {
            $table->dropColumn(['provider_name', 'provider_phone']);
            $table->string('verification_code', 10)->nullable()->change();
        });
    }
};
