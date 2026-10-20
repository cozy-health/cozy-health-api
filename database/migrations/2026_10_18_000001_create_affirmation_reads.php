<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_affirmation_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->timestamp('read_at');
            $table->primary(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_affirmation_reads');
    }
};
