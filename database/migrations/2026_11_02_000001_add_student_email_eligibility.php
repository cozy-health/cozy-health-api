<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_applications', function (Blueprint $table) {
            $table->string('verification_method', 20)->default('document');
        });
        Schema::create('student_email_domains', function (Blueprint $table) {
            $table->id(); $table->string('domain', 253)->unique(); $table->string('institution', 200);
            $table->boolean('enabled')->default(true); $table->unsignedInteger('version')->default(1); $table->timestamps();
        });
        Schema::create('student_email_claims', function (Blueprint $table) {
            $table->string('email_key', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestamp('created_at');
        });
        Schema::create('student_email_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('email'); $table->string('email_key', 64); $table->string('domain', 253);
            $table->string('token_hash', 64); $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0); $table->timestamp('expires_at'); $table->timestamps();
        });
        Schema::create('student_renewal_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('application_id')->constrained('student_applications')->cascadeOnDelete();
            $table->unsignedTinyInteger('days'); $table->string('status', 20)->default('pending');
            $table->string('email_key', 64); $table->timestamp('queued_at')->nullable(); $table->timestamps();
            $table->unique(['application_id', 'days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_renewal_reminders'); Schema::dropIfExists('student_email_challenges');
        Schema::dropIfExists('student_email_claims'); Schema::dropIfExists('student_email_domains');
        Schema::table('student_applications', fn (Blueprint $table) => $table->dropColumn('verification_method'));
    }
};
