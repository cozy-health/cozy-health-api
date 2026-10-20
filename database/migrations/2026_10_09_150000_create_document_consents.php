<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['user' => 'users', 'provider' => 'providers'] as $owner => $owners) {
            Schema::create($owner.'_consents', function (Blueprint $table) use ($owner, $owners) {
                $table->id();
                $table->foreignId($owner.'_id')->constrained($owners)->cascadeOnDelete();
                $table->string('document_type', 40);
                $table->string('version', 80);
                $table->string('status', 20);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('recorded_at');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->unique([$owner.'_id', 'document_type', 'version']);
            });
            Schema::create($owner.'_consent_events', function (Blueprint $table) use ($owner, $owners) {
                $table->id();
                $table->foreignId($owner.'_id')->constrained($owners)->cascadeOnDelete();
                $table->string('document_type', 40);
                $table->string('version', 80);
                $table->string('status', 20);
                $table->timestamp('created_at');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['user', 'provider'] as $owner) {
            Schema::dropIfExists($owner.'_consent_events');
            Schema::dropIfExists($owner.'_consents');
        }
    }
};
