<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->char('country_code', 2)->nullable());
        Schema::create('push_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('device_id', 128);
            $t->string('platform', 10);
            $t->string('provider', 10);
            $t->string('environment', 10)->default('production');
            $t->char('token_hash', 64)->unique();
            $t->text('token');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['user_id', 'device_id']);
        });
        Schema::create('content_articles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 100)->unique();
            $t->string('title');
            $t->string('category', 50)->index();
            $t->text('excerpt');
            $t->longText('body');
            $t->unsignedSmallInteger('read_time_minutes');
            $t->string('license')->nullable();
            $t->string('source_url', 1000)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::table('saved_articles', function (Blueprint $t) {
            $t->text('excerpt')->nullable();
            $t->longText('body')->nullable();
        });
        Schema::create('crisis_resources', function (Blueprint $t) {
            $t->id();
            $t->char('country_code', 2)->index();
            $t->string('name');
            $t->string('phone', 64)->nullable();
            $t->string('website', 1000)->nullable();
            $t->string('hours')->nullable();
            $t->json('languages')->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crisis_resources');
        Schema::table('saved_articles', fn (Blueprint $t) => $t->dropColumn(['excerpt', 'body']));
        Schema::dropIfExists('content_articles');
        Schema::dropIfExists('push_devices');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('country_code'));
    }
};
