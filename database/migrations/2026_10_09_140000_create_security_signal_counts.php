<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('security_signal_counts', function (Blueprint $table) {
            $table->date('day'); $table->string('event', 40); $table->unsignedBigInteger('count')->default(0);
            $table->primary(['day', 'event']);
        });
    }
    public function down(): void { Schema::dropIfExists('security_signal_counts'); }
};
