<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_devices', fn (Blueprint $t) => $t->char('cleanup_hash', 64)->nullable());
        Schema::table('user_profiles', function (Blueprint $t) {
            $t->boolean('push_consent')->default(false);
            $t->json('reminder_settings')->nullable();
        });
        Schema::create('push_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('category', 20);
            $t->date('local_date');
            $t->string('timezone', 64);
            $t->char('settings_hash', 64)->nullable();
            $t->timestamp('scheduled_at');
            $t->timestamp('eligible_at');
            $t->timestamp('expires_at');
            $t->string('status', 20)->default('queued');
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('send_started_at')->nullable();
            $t->date('dispatch_date')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'category', 'local_date']);
            $t->index(['status', 'eligible_at']);
            $t->index(['user_id', 'dispatch_date']);
        });
        Schema::create('push_attempts', function (Blueprint $t) {
            $t->id();
            $t->uuid('reminder_id');
            $t->foreign('reminder_id')->references('id')->on('push_reminders')->cascadeOnDelete();
            $t->unsignedBigInteger('device_id');
            $t->string('status', 20)->default('queued');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('retry_at')->nullable();
            $t->timestamps();
            $t->unique(['reminder_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('push_devices', fn (Blueprint $t) => $t->dropColumn('cleanup_hash'));
        Schema::dropIfExists('push_attempts');
        Schema::dropIfExists('push_reminders');
        Schema::table('user_profiles', fn (Blueprint $t) => $t->dropColumn(['push_consent', 'reminder_settings']));
    }
};
