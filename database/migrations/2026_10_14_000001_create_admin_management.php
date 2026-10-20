<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The retired check-in migration removed these catalogs. Restore catalogs only,
        // while continuing to use mood_entries as the source of patient check-ins.
        foreach (['feelings', 'feeling_causes', 'coping_mechanisms'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id(); $t->string('name'); $t->string('slug', 100)->unique();
                if ($name !== 'feeling_causes') $t->text('description')->nullable();
                if ($name !== 'feelings') $t->string('icon')->nullable();
                $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_active')->default(true); $t->timestamps();
            });
        }
        Schema::create('feeling_expressions', function (Blueprint $t) {
            $t->id(); $t->foreignId('feeling_id')->constrained()->restrictOnDelete();
            $t->string('name'); $t->string('slug', 100)->unique(); $t->string('emoji', 20)->nullable(); $t->text('description')->nullable();
            $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::table('mood_entries', fn (Blueprint $t) => $t->string('expression_slug', 100)->nullable());
        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('actor_id')->nullable()->index(); $t->string('action');
            $t->string('resource'); $t->string('resource_id'); $t->json('changed_fields'); $t->timestamp('created_at');
        });
        Schema::create('wellness_catalog_aliases', function (Blueprint $t) {
            $t->id(); $t->string('resource'); $t->string('alias'); $t->string('slug'); $t->unique(['resource', 'alias']);
        });
        Schema::create('admin_quiz_drafts', function (Blueprint $t) {
            $t->id(); $t->foreignId('quiz_id')->nullable()->unique()->constrained('quizzes')->nullOnDelete();
            $t->string('title'); $t->json('definition'); $t->string('status')->default('draft'); $t->timestamps();
        });
        Schema::create('wellness_challenges', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug')->unique(); $t->text('description');
            $t->json('tasks'); $t->unsignedSmallInteger('duration_days'); $t->boolean('is_active')->default(false);
            $t->unsignedInteger('sort_order')->default(0); $t->timestamps();
        });
        Schema::create('challenge_enrollments', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('challenge_id')->constrained('wellness_challenges')->restrictOnDelete();
            $t->json('definition'); $t->timestamps(); $t->unique(['user_id', 'challenge_id']);
        });
        Schema::create('challenge_completions', function (Blueprint $t) {
            $t->id(); $t->foreignId('enrollment_id')->constrained('challenge_enrollments')->cascadeOnDelete();
            $t->unsignedSmallInteger('day'); $t->timestamp('completed_at'); $t->unique(['enrollment_id', 'day']);
        });
        Schema::create('subscription_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable()->index(); $t->string('subscription_id');
            $t->string('plan')->nullable(); $t->string('status'); $t->string('source'); $t->timestamp('created_at');
        });
        Schema::create('email_delivery_logs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('event'); $t->string('status')->index();
            $t->string('message_key', 64)->nullable()->index(); $t->string('provider_event_key', 64)->nullable()->unique();
            $t->string('queue_key', 64)->nullable()->index();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::table('mood_entries', fn (Blueprint $t) => $t->dropColumn('expression_slug'));
        foreach (['email_delivery_logs', 'subscription_events', 'challenge_completions', 'challenge_enrollments', 'wellness_challenges', 'admin_quiz_drafts', 'wellness_catalog_aliases', 'admin_audit_logs', 'feeling_expressions', 'coping_mechanisms', 'feeling_causes', 'feelings'] as $table) Schema::dropIfExists($table);
    }
};
