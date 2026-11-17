<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->boolean('email_invalid')->default(false);
            $table->timestamp('email_unsubscribed_at')->nullable();
        });
        Schema::table('bounces', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bounces', fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_id'));
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn(['email_invalid', 'email_unsubscribed_at']));
    }
};
