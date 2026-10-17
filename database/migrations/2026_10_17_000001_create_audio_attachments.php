<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audio_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->uuid('client_id');
            $table->string('purpose');
            $table->foreignId('provider_client_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk');
            $table->string('storage_key');
            $table->string('media_type');
            $table->unsignedInteger('byte_size');
            $table->unsignedSmallInteger('duration');
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'client_id']);
        });
        Schema::table('journal_entries', fn (Blueprint $table) => $table->foreignUuid('audio_attachment_id')->nullable()->constrained('audio_attachments')->nullOnDelete());
        Schema::table('provider_messages', fn (Blueprint $table) => $table->foreignUuid('audio_attachment_id')->nullable()->constrained('audio_attachments')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('provider_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('audio_attachment_id'));
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('audio_attachment_id'));
        Schema::dropIfExists('audio_attachments');
    }
};
