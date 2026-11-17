<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_email_domains', function (Blueprint $t) {
            $t->string('country', 2)->default('NG');
            $t->string('institution_type')->default('tertiary');
        });
        Schema::create('student_school_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('domain', 253)->index();
            $t->string('institution', 200);
            $t->string('website', 500);
            $t->string('status')->default('pending')->index();
            $t->text('review_note')->nullable();
            $t->json('notices_sent')->nullable();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'domain']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('student_school_requests');
        Schema::table('student_email_domains', fn (Blueprint $t) => $t->dropColumn(['country', 'institution_type']));
    }
};
