<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic records exclusively for the isolated local admin preview. */
class AdminPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'admin-preview') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'admin-preview.sqlite') {
            throw new \RuntimeException('Admin preview requires local SQLite admin-preview.sqlite.');
        }
        if (DB::table('users')->exists()) {
            $this->command?->info('Preview already populated; existing review changes preserved.');

            return;
        }
        DB::transaction(function () {
            $admin = User::create(['name' => 'Cozy Local Admin', 'email' => 'admin@cozy.local', 'password' => 'CozyLocal!Review2026', 'is_active' => true]);
            $admin->forceFill(['role' => 'admin'])->save();
            $end = CarbonImmutable::now('UTC')->startOfMonth();
            $users = [];
            $password = bcrypt('SyntheticFixture!2026');
            for ($i = 0; $i < 90; $i++) {
                $joined = $end->subDays(100 - intdiv($i, 6) * 7);
                $users[] = DB::table('users')->insertGetId(['name' => 'Preview member '.($i + 1), 'email' => 'member'.($i + 1).'@example.test', 'password' => $password, 'role' => 'user', 'is_demo' => false, 'is_active' => true, 'created_at' => $joined, 'updated_at' => $joined]);
                DB::table('users')->insert(['name' => 'Preview reader '.($i + 1), 'email' => 'reader'.($i + 1).'@example.test', 'password' => $password, 'role' => 'user', 'is_demo' => false, 'is_active' => true, 'created_at' => $joined, 'updated_at' => $joined]);
            }
            for ($day = 90; $day > 0; $day--) {
                $date = $end->subDays($day)->setTime(12, 0);
                $moods = [];
                $journals = [];
                $quizzes = [];
                foreach ($users as $i => $user) {
                    $joined = $end->subDays(100 - intdiv($i, 6) * 7);
                    if ($date->lt($joined)) {
                        continue;
                    }
                    $mood = ['good', 'calm', 'anxious'][intdiv($i % 30, 10)];
                    $moods[] = ['id' => (string) Str::uuid(), 'user_id' => $user, 'mood' => $mood, 'intensity' => 3 + $day % 5, 'triggers' => json_encode(['work', 'sleep']), 'coping_strategies' => json_encode([DB::table('coping_mechanisms')->orderBy('id')->value('slug')]), 'coping_helped' => ['yes', 'somewhat', 'no'][intdiv($i % 30, 10)], 'is_crisis_flagged' => $i < 10, 'created_at' => $date, 'updated_at' => $date];
                    if ($day % 2 === 0) {
                        $journals[] = ['id' => (string) Str::uuid(), 'user_id' => $user, 'type' => 'free', 'body' => 'Synthetic local journal. Never read by admin analytics.', 'is_draft' => false, 'created_at' => $date, 'updated_at' => $date];
                    }
                    if ($day % 3 === 0) {
                        $quizzes[] = ['id' => (string) Str::uuid(), 'user_id' => $user, 'quiz_slug' => 'local-reflection', 'quiz_title' => 'Local reflection', 'answers' => '[]', 'score' => 1, 'completed_at' => $date, 'created_at' => $date, 'updated_at' => $date];
                    }
                }
                if ($moods) {
                    DB::table('mood_entries')->insert($moods);
                }
                if ($journals) {
                    DB::table('journal_entries')->insert($journals);
                }
                if ($quizzes) {
                    DB::table('quiz_attempts')->insert($quizzes);
                }
            }
            $group = DB::table('community_groups')->insertGetId(['name' => 'Everyday wellbeing', 'description' => 'A synthetic community for local review.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $challenge = DB::table('wellness_challenges')->insertGetId(['name' => 'A mindful week', 'slug' => 'mindful-week', 'description' => 'Small moments of reflection.', 'duration_days' => 7, 'tasks' => json_encode(array_fill(0, 7, 'Take a quiet moment')), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($users as $i => $user) {
                DB::table('community_memberships')->insert(['user_id' => $user, 'group_id' => $group, 'created_at' => now(), 'updated_at' => now()]);
                $sub = (string) Str::uuid();
                DB::table('subscription_statuses')->insert(['id' => $sub, 'user_id' => $user, 'plan' => 'premium', 'status' => $i < 40 ? 'active' : 'expired', 'platform' => $i % 2 ? 'ios' : 'android', 'started_at' => $end->subDays(45), 'expires_at' => $i < 40 ? now()->addMonth() : now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ([70, 20, 4] as $days) {
                    DB::table('subscription_events')->insert(['user_id' => $user, 'subscription_id' => $sub, 'plan' => 'premium', 'status' => 'active', 'source' => 'local-preview', 'created_at' => $end->subDays($days)]);
                }
                $days = [70, 20, 4][intdiv($i, 30)];
                $enrollment = DB::table('challenge_enrollments')->insertGetId(['user_id' => $user, 'challenge_id' => $challenge, 'definition' => '{}', 'created_at' => $end->subDays($days), 'updated_at' => now()]);
                DB::table('challenge_completions')->insert(['enrollment_id' => $enrollment, 'day' => 1, 'completed_at' => $end->subDays($days)]);
                DB::table('push_devices')->insert(['user_id' => $user, 'device_id' => 'local-'.$i, 'platform' => $i % 2 ? 'ios' : 'android', 'provider' => 'fcm', 'environment' => 'sandbox', 'token_hash' => hash('sha256', 'preview-'.$i), 'token' => 'synthetic-not-a-real-device', 'expires_at' => now()->addMonth(), 'created_at' => $end->subDays(50), 'updated_at' => now()]);
                if ($i < 8) {
                    $post = (string) Str::uuid();
                    DB::table('community_posts')->insert(['id' => $post, 'user_id' => $user, 'group_id' => $group, 'body' => 'Synthetic community post for moderation review.', 'status' => 'pending', 'is_anonymous' => true, 'guidelines_version' => 'local-v1', 'guidelines_accepted_at' => now(), 'created_at' => now()->subDays(8 - $i), 'updated_at' => now()]);
                    DB::table('community_reports')->insert(['target_type' => 'post', 'target_id' => $post, 'reason' => 'spam', 'snapshot' => '{}', 'status' => $i < 6 ? 'pending' : 'resolved', 'due_at' => now()->subHours(12)->addHours($i * 8), 'created_at' => now()->subDays(8 - $i), 'updated_at' => now()]);
                    DB::table('support_reports')->insert(['user_id' => $user, 'subject' => 'Local support request '.($i + 1), 'message' => 'Synthetic request: help updating my account.', 'status' => $i < 5 ? 'open' : 'resolved', 'admin_notes' => 'Saved note for preservation testing.', 'created_at' => now()->subDays(8 - $i), 'updated_at' => now()]);
                }
            }
            DB::table('subscription_packages')->insert(['package_name' => 'Cozy Plus', 'slug' => 'cozy-plus', 'description' => 'Local review package', 'price' => 2500, 'currency' => 'NGN', 'duration' => 'monthly', 'duration_days' => 30, 'features' => '["Wellness tools"]', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('admin_audit_logs')->insert(['actor_id' => $admin->id, 'action' => 'create', 'resource' => 'communities', 'resource_id' => $group, 'changed_fields' => '["name"]', 'created_at' => now()]);
        });
    }
}
