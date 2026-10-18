<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('community_reactions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 8);
            $table->uuid('target_id');
            $table->primary(['user_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('community_reactions'); }
};
