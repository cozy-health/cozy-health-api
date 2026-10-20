<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mood_checkin_coping_mechanisms');
        Schema::dropIfExists('mood_checkin_causes');
        Schema::dropIfExists('mood_checkins');
        Schema::dropIfExists('coping_mechanisms');
        Schema::dropIfExists('feeling_causes');
        Schema::dropIfExists('feeling_expressions');
        Schema::dropIfExists('feelings');
    }

    public function down(): void
    {
        Schema::create('feelings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('feeling_expressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feeling_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('feeling_causes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('coping_mechanisms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('mood_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feeling_expression_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('intensity');
            $table->text('journal')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mood_checkin_causes', function (Blueprint $table) {
            $table->foreignId('mood_checkin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feeling_cause_id')->constrained()->restrictOnDelete();
            $table->primary(['mood_checkin_id', 'feeling_cause_id']);
        });

        Schema::create('mood_checkin_coping_mechanisms', function (Blueprint $table) {
            $table->foreignId('mood_checkin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coping_mechanism_id')->constrained()->restrictOnDelete();
            $table->primary(['mood_checkin_id', 'coping_mechanism_id']);
        });
    }
};
