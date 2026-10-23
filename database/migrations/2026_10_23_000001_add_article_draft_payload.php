<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_articles', fn (Blueprint $table) => $table->json('draft_payload')->nullable());
    }
    public function down(): void
    {
        Schema::table('content_articles', fn (Blueprint $table) => $table->dropColumn('draft_payload'));
    }
};
