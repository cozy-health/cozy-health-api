<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('journal_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_client_link_id')->constrained()->cascadeOnDelete();
            $table->string('journal_entry_id', 36)->index();
            $table->uuid('client_id');
            $table->string('source_revision', 64);
            $table->longText('snapshot')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_client_link_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_shares');
    }
};
