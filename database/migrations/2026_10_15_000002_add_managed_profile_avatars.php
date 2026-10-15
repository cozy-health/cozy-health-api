<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('avatar_preset_id', 40)->nullable();
            $table->string('avatar_object_key')->nullable();
            $table->unsignedInteger('avatar_revision')->default(0);
        });
        Schema::create('avatar_photo_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('object_key');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_photo_deletions');
        Schema::table('user_profiles', fn (Blueprint $table) => $table->dropColumn(['avatar_preset_id', 'avatar_object_key', 'avatar_revision']));
    }
};
