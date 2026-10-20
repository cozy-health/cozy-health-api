<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('quiz_push_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('push_devices')->cascadeOnDelete();
            $t->string('status', 20)->default('queued');
            $t->timestamps();
            $t->unique(['notification_id', 'device_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('quiz_push_attempts'); }
};
