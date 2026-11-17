<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $table) {
            $table->uuid('request_id')->nullable();
            $table->unsignedInteger('support_revision')->default(0);
            $table->unique(['user_id', 'request_id']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('support_report_id')->constrained('support_reports')->cascadeOnDelete();
            $table->string('author', 12);
            $table->text('body');
            $table->uuid('request_id');
            $table->unsignedInteger('sequence');
            $table->uuid('delivery_id')->nullable();
            $table->timestamps();
            $table->unique(['support_report_id', 'author', 'request_id']);
            $table->unique(['support_report_id', 'sequence']);
        });
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('kind', 12);
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->text('changes')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamp('verified_at');
            $table->string('identity_fingerprint', 64);
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('support_messages');
        Schema::table('support_reports', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'request_id']);
            $table->dropColumn(['request_id', 'support_revision']);
        });
    }
};
