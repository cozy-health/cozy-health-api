<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_affirmation_assignments', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            // Retain the rotation cursor even if the catalog entry is deleted.
            $table->unsignedBigInteger('affirmation_id');
            $table->dateTime('assigned_at');
            $table->dateTime('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_affirmation_assignments');
    }
};
