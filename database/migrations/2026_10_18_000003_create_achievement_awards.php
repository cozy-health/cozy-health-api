<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_achievement_awards', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('milestone', 32);
            $table->date('earned_date');
            $table->primary(['user_id', 'milestone']);
        });
    }
    public function down(): void { Schema::dropIfExists('user_achievement_awards'); }
};
