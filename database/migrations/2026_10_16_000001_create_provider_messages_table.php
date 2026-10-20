<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_client_link_id')->constrained('provider_client_links')->cascadeOnDelete();
            $table->string('sender', 8);
            $table->uuid('client_id');
            $table->text('content')->nullable();
            $table->json('records')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_client_link_id', 'sender', 'client_id'], 'provider_message_retry_unique');
            $table->index(['provider_client_link_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_messages');
    }
};
