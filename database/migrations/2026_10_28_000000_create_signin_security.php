<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signin_security_preferences', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('method', 20)->default('email');
            $table->json('questions')->nullable();
            $table->string('verified_email_hash', 64)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });
        Schema::create('signin_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('owner');
            $table->string('token_hash', 64)->unique();
            $table->string('email_hash', 64);
            $table->string('method', 20);
            $table->string('purpose', 20)->default('login');
            $table->string('device_name', 120);
            $table->json('question_ids')->nullable();
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('settings_used_at')->nullable();
            $table->timestamps();
        });
        Schema::table('known_devices', function (Blueprint $table) {
            $table->string('proof_hash', 64)->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('security_device_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('security_device_hash'));
        Schema::table('known_devices', fn (Blueprint $table) => $table->dropIndex('known_devices_proof_hash_index'));
        Schema::table('known_devices', fn (Blueprint $table) => $table->dropColumn(['proof_hash', 'verified_at', 'last_used_at']));
        Schema::dropIfExists('signin_challenges');
        Schema::dropIfExists('signin_security_preferences');
    }
};
