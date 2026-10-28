<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streak_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->date('earning_starts');
        });
        Schema::create('streak_completions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_id');
            $table->date('completed_date');
            $table->primary(['user_id', 'source_id']);
            $table->index(['user_id', 'completed_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streak_completions');
        Schema::dropIfExists('streak_accounts');
    }
};
