<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['password_reset_tokens', 'provider_password_reset_tokens', 'email_verification_codes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedTinyInteger('failed_attempts')->default(0));
        }
        Schema::table('email_verification_codes', fn (Blueprint $table) => $table->string('email_key', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('email_verification_codes', fn (Blueprint $table) => $table->dropColumn('email_key'));
        foreach (['password_reset_tokens', 'provider_password_reset_tokens', 'email_verification_codes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('failed_attempts'));
        }
    }
};
