<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_invalid')->default(false);
            $table->timestamp('email_unsubscribed_at')->nullable();
        });
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('code_hash'); $table->timestamp('expires_at');
        });
        Schema::create('bounces', function (Blueprint $table) {
            $table->id(); $table->string('event_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 40); $table->timestamp('occurred_at'); $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('bounces'); Schema::dropIfExists('email_verification_codes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['email_invalid', 'email_unsubscribed_at']));
    }
};
