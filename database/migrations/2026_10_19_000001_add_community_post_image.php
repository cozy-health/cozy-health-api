<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table) {
            $table->string('image_object_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('community_posts', fn (Blueprint $table) => $table->dropColumn('image_object_key'));
    }
};
