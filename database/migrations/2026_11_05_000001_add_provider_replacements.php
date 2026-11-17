<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_client_links', function (Blueprint $table) {
            $table->unsignedBigInteger('replacement_of_link_id')->nullable()->index();
            $table->uuid('replacement_request_id')->nullable()->unique();
            $table->unsignedBigInteger('replacement_qualifying_message_id')->nullable();
            $table->timestamp('replacement_started_at')->nullable();
            $table->string('replacement_status', 16)->nullable();
        });
        Schema::create('provider_replacement_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('provider_client_links')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_replacement_requests');
        Schema::table('provider_client_links', function (Blueprint $table) {
            $table->dropUnique(['replacement_request_id']);
            $table->dropIndex(['replacement_of_link_id']);
            $table->dropColumn(['replacement_of_link_id', 'replacement_request_id', 'replacement_qualifying_message_id', 'replacement_started_at', 'replacement_status']);
        });
    }
};
