<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_metric_releases', function (Blueprint $table) {
            $table->string('release_key', 40)->primary();
            $table->json('payload'); // Suppressed aggregates only; no identity/content.
            $table->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('admin_metric_releases'); }
};
