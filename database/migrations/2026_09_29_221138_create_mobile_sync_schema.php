<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. mood_entries
        Schema::create('mood_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mood', 20);
            $table->integer('intensity');                    // was mood_intensity
            $table->json('body_sensations')->nullable();
            $table->json('triggers')->nullable();
            $table->string('custom_trigger', 255)->nullable();
            $table->tinyInteger('sleep_quality')->nullable();
            $table->tinyInteger('energy_level')->nullable();
            $table->text('note')->nullable();                // was notes
            $table->json('coping_strategies')->nullable();
            $table->string('coping_helped', 20)->nullable();
            $table->boolean('is_crisis_flagged')->default(false);
            $table->boolean('is_deleted')->default(false);   // soft delete
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'mood']);
            $table->index(['is_crisis_flagged']);
        });

        // 2. journal_entries
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);                      // free | guided | voice
            $table->string('title', 255)->nullable();
            $table->text('body');
            $table->string('voice_url', 500)->nullable();
            $table->integer('voice_duration')->nullable();
            $table->text('transcription')->nullable();
            $table->uuid('prompt_id')->nullable();
            $table->string('prompt_text', 500)->nullable();
            $table->json('tags')->nullable();
            $table->uuid('linked_mood_entry_id')->nullable();
            $table->integer('word_count')->default(0);
            $table->boolean('is_crisis_flagged')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type']);
            $table->index(['linked_mood_entry_id']);
        });

        // 3. safety_plans (one per user)
        Schema::create('safety_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('warning_signs')->nullable();
            $table->json('coping_strategies')->nullable();
            $table->json('distractions')->nullable();
            $table->json('people')->nullable();               // [{name, phone, relationship}]
            $table->json('professionals')->nullable();        // [{name, role, phone}]
            $table->json('environment_steps')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
        });

        // 4. chat_conversations
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('last_message_preview', 255)->nullable();
            $table->integer('message_count')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'updated_at']);
        });

        // 5. chat_messages
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')
                  ->on('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);                       // user | assistant | system
            $table->text('content');
            $table->string('status', 20)->default('sent');    // sending | sent | failed
            $table->boolean('is_crisis_flagged')->default(false);
            $table->string('model_used', 50)->nullable();
            $table->integer('tokens_used')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();
            
            $table->index(['conversation_id', 'created_at']);
        });

        // 6. quiz_attempts
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('quiz_slug', 100);
            $table->string('quiz_title', 255);
            $table->json('answers');
            $table->integer('score');
            $table->string('interpretation', 50)->nullable();
            $table->boolean('is_crisis_flagged')->default(false);
            $table->timestamp('completed_at');
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'quiz_slug', 'completed_at']);
        });

        // 7. saved_articles
        Schema::create('saved_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('article_id', 100);
            $table->string('article_slug', 100);
            $table->string('title', 255);
            $table->string('category', 50);
            $table->integer('read_time_minutes');
            $table->timestamp('saved_at');
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();
            
            $table->unique(['user_id', 'article_id']);
            $table->index(['user_id', 'saved_at']);
        });

        // 8. app_notifications
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 255);
            $table->text('body');
            $table->string('deep_link', 500)->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'is_read', 'created_at']);
        });

        // 9. subscription_statuses
        Schema::create('subscription_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('plan', 20)->default('free');
            $table->string('status', 30)->default('active');
            $table->string('platform', 20)->nullable();
            $table->string('product_id', 100)->nullable();
            $table->string('transaction_id', 255)->nullable();
            $table->string('original_transaction_id', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('last_checked')->nullable();
            $table->timestamps();
        });

        // 10. user_profiles (extends users; 1-to-1)
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('username', 50)->unique()->nullable();
            $table->string('email', 255);
            $table->string('avatar_url', 500)->nullable();
            $table->string('bio', 150)->nullable();
            
            // Preferences (flat columns, not JSON)
            $table->string('theme', 20)->default('system');
            $table->string('accent_color', 20)->default('#0460D8');
            $table->string('language', 10)->default('en');
            $table->boolean('reduce_motion')->default(false);
            $table->boolean('high_contrast')->default(false);
            $table->boolean('haptics_enabled')->default(true);
            $table->decimal('text_size', 3, 2)->default(1.00);
            $table->boolean('show_stats')->default(false);
            $table->boolean('show_username')->default(true);
            
            // Notification preferences
            $table->boolean('notifications_master')->default(true);
            $table->boolean('daily_checkin_enabled')->default(true);
            $table->string('daily_checkin_time', 10)->default('09:00');
            $table->boolean('journal_reminder_enabled')->default(false);
            $table->boolean('comments_notifications')->default(true);
            $table->boolean('achievements_notifications')->default(true);
            $table->boolean('marketing_notifications')->default(false);
            
            // Metadata
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('subscription_statuses');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('saved_articles');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('safety_plans');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('mood_entries');
    }
};
