<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_sub', 191)->nullable()->unique()->after('id');
            $table->string('apple_sub', 191)->nullable()->unique()->after('google_sub');
            $table->timestamp('google_linked_at')->nullable();
            $table->timestamp('apple_linked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_sub']);
            $table->dropUnique(['apple_sub']);
            $table->dropColumn(['google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at']);
        });
    }
};
