<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_purchases', fn (Blueprint $table) => $table->json('plan_details')->nullable());
        Schema::create('store_plan_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_purchase_id')->constrained('store_purchases')->cascadeOnDelete();
            $table->string('dedupe_key', 64)->unique();
            $table->string('email_key', 64);
            $table->json('details');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'ready_at', 'queued_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('store_plan_notifications');
        Schema::table('store_purchases', fn (Blueprint $table) => $table->dropColumn('plan_details'));
    }
};
