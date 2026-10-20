<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('provider_client_links', function (Blueprint $table) {
            $table->string('verification_actor', 16)->default('patient');
            $table->string('invitation_code_lookup', 64)->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('provider_acknowledged_at')->nullable();
            $table->index(['provider_email', 'invitation_code_lookup'], 'provider_invitation_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('provider_client_links', function (Blueprint $table) {
            $table->dropIndex('provider_invitation_lookup');
            $table->dropColumn(['verification_actor', 'invitation_code_lookup', 'invitation_sent_at', 'provider_acknowledged_at']);
        });
    }
};
