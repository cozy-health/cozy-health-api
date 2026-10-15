<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('next_of_kin_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('contact')->nullable();
            $table->timestamp('client_updated_at', 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('next_of_kin_contacts');
    }
};
