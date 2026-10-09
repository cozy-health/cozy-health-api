<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['refresh_tokens' => 'users', 'provider_refresh_tokens' => 'providers'] as $name => $owner) {
            Schema::create($name, function (Blueprint $table) use ($owner) {
                $table->id();
                $table->foreignId('user_id')->constrained($owner)->cascadeOnDelete();
                $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->string('device_name', 120);
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamp('created_at');
                $table->timestamp('revoked_at')->nullable();
            });
        }
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_hash', 64);
            $table->string('event_type', 40);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['email_hash', 'event_type', 'created_at']);
        });
        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('device_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['owner_type', 'owner_id', 'device_hash']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('provider_refresh_tokens');
        Schema::dropIfExists('refresh_tokens');
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('ip_address'));
    }
};
