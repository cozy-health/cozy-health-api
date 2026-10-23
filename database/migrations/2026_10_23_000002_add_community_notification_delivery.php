<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('community_notification_events', function (Blueprint $table) {
            $table->uuid('notification_id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->uuid('post_id');
            $table->uuid('comment_id')->nullable();
            $table->timestamps();
        });
        Schema::create('community_push_attempts', function (Blueprint $table) {
            $table->id(); $table->uuid('notification_id'); $table->unsignedBigInteger('device_id');
            $table->string('status')->default('queued'); $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable(); $table->timestamps();
            $table->unique(['notification_id', 'device_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('community_push_attempts'); Schema::dropIfExists('community_notification_events');
    }
};
