<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_articles', function (Blueprint $table) {
            $table->text('license')->nullable();
            $table->text('source_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saved_articles', fn (Blueprint $table) => $table->dropColumn(['license', 'source_url']));
    }
};
