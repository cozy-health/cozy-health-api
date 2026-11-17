<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('store_purchases', fn (Blueprint $table) => $table->unsignedInteger('plan_version')->default(0));
        Schema::table('store_plan_notifications', fn (Blueprint $table) => $table->unsignedInteger('plan_version')->default(0));
    }
    public function down(): void
    {
        Schema::table('store_plan_notifications', fn (Blueprint $table) => $table->dropColumn('plan_version'));
        Schema::table('store_purchases', fn (Blueprint $table) => $table->dropColumn('plan_version'));
    }
};
