<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id(); $table->string('event', 40)->index(); $table->timestamp('created_at')->index();
        });
    }
    public function down(): void { Schema::dropIfExists('analytics_events'); }
};
