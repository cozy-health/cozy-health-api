<?php

namespace Database\Seeders;

use App\Models\{AppNotification, ChatConversation, ChatMessage, JournalEntry, MoodEntry, QuizAttempt, SafetyPlan, SubscriptionStatus, User, UserProfile};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class DemoAccountSeeder extends Seeder
{
    public const EMAIL = 'demo@cozyhealth.org';
    public const PASSWORD = 'CozyDemo2026!';

    public function run(): void
    {
        DB::transaction(function () {
            $user = User::where('email', self::EMAIL)->lockForUpdate()->first();
            if ($user && ! $user->is_demo) {
                throw new RuntimeException('Refusing to replace a non-demo account at the reserved demo email.');
            }
            $user ??= new User(['email' => self::EMAIL]);
            $user->forceFill([
                'name' => 'Sample User', 'timezone' => 'UTC', 'is_demo' => true,
                'is_active' => true, 'email_verified_at' => now(),
                'password' => $user->exists && Hash::check(self::PASSWORD, $user->password)
                    ? $user->password : Hash::make(self::PASSWORD),
            ])->save();
            $today = $user->localToday();
            $id = fn (string $key) => Uuid::uuid5(Uuid::NAMESPACE_URL, "cozyhealth-demo:{$user->id}:$key")->toString();
            $put = function (string $class, string $key, array $data) use ($user, $id) {
                $row = $class::withoutGlobalScopes()->firstOrNew(['id' => $id($key), 'user_id' => $user->id]);
                $row->forceFill($data)->save();

                return $row;
            };

            $preferences = [
                'focus_areas' => ['stress', 'sleep', 'self-growth'],
                'current_challenges' => ['work', 'family'],
                'check_in_frequency' => 'daily', 'attribution' => 'friend',
                'completed_at' => $today->copy()->subDays(29)->toIso8601String(), 'skipped' => false,
            ];
            UserProfile::withoutGlobalScopes()->updateOrCreate(['user_id' => $user->id], [
                'id' => $user->profile()->withoutGlobalScopes()->value('id') ?? $id('profile'),
                'name' => 'Sample User', 'email' => self::EMAIL,
                'bio' => 'Making room for small moments of reflection.', 'show_stats' => true,
                'preferences' => $preferences, 'onboarding_completed_at' => $preferences['completed_at'],
            ]);

            // 30 entries spanning 30 days, with a gap just before the 14-day streak.
            $offsets = [...range(29, 15), 29, ...range(13, 0)];
            $moods = ['good', 'okay', 'great', 'low', 'good', 'okay'];
            foreach ($offsets as $i => $daysAgo) {
                $time = $today->copy()->subDays($daysAgo)->setTime(8 + ($i % 3) * 5, 10 + ($i % 4) * 10);
                // Today's sample always falls before the seeding time.
                if ($daysAgo === 0) {
                    $time = now();
                }
                $put(MoodEntry::class, "mood-$i", [
                    'mood' => $moods[$i % count($moods)], 'intensity' => [7, 5, 8, 4, 6, 5][$i % 6],
                    'triggers' => [['Work'], ['Family'], ['Sleep']][$i % 3],
                    'sleep_quality' => 3 + ($i % 3), 'energy_level' => 3 + ($i % 3),
                    'note' => ['A walk helped me slow down.', 'A busy day; taking time to pause.', 'Enjoyed a quiet evening with a book.'][$i % 3],
                    'coping_strategies' => ['Walking', 'Breathing'], 'is_crisis_flagged' => false, 'is_deleted' => false,
                    'client_created_at' => $time, 'client_updated_at' => $time, 'created_at' => $time, 'updated_at' => $time,
                ]);
            }

            $journals = [
                ['free', 'A quieter morning', 'I made tea and sat by the window before starting work. Having a little space helped me choose one task to begin with.'],
                ['free', 'A walk after work', 'The air was cool this evening. I walked around the block and noticed the trees along the street. It was a gentle way to end a busy day.'],
                ['guided', 'What helped today', 'A friend checked in, and we talked about our weekend plans. I appreciated having someone listen without needing to solve everything.'],
                ['free', 'Making room for rest', 'I turned off a few notifications and put my phone away during dinner. I would like to try that again tomorrow.'],
                ['voice', 'A small pause', 'Today I took a short walk after lunch. I noticed the breeze and gave myself a few quiet minutes before going back to work.'],
                ['free', 'A family conversation', 'We made time for a relaxed call. I shared one thing I enjoyed this week and listened to the small stories from everyone else.'],
                ['guided', 'One thing to carry forward', 'Preparing my bag the night before made the morning easier. I can keep that small habit without needing every day to go perfectly.'],
                ['free', 'Trying something new', 'I cooked a new recipe today. It took longer than expected, but I enjoyed paying attention to something practical and different.'],
                ['voice', 'An evening reflection', 'This evening I made tea and read a few pages of a book. I am learning that a simple routine can help me settle after a busy day.'],
                ['free', 'Looking back gently', 'Some days felt easier than others this month. Writing a few lines helped me notice what supported me, especially walks, regular meals, and time with friends.'],
            ];
            foreach ($journals as $i => [$type, $title, $body]) {
                $time = $today->copy()->subDays(29 - $i * 3)->setTime(20, 15);
                $voice = $type === 'voice' ? ($i === 4 ? 'small-pause' : 'evening-reflection') : null;
                $put(JournalEntry::class, "journal-$i", [
                    'type' => $type, 'title' => $title, 'body' => $body,
                    'voice_url' => $voice ? url("/demo/voice/$voice.mp3") : null,
                    'voice_duration' => $voice ? 8 : null,
                    'transcription' => $voice ? $body : null,
                    'prompt_text' => $type === 'guided' ? 'What made today a little easier?' : null,
                    'tags' => ['reflection'], 'word_count' => str_word_count($body),
                    'is_crisis_flagged' => false, 'is_deleted' => false, 'is_draft' => false,
                    'client_created_at' => $time, 'client_updated_at' => $time, 'created_at' => $time, 'updated_at' => $time,
                ]);
            }

            $conversations = [
                ['Finding a workday rhythm', 'My workday feels busy. I would like a simpler routine.', 'We can start with one small pause. What time of day would a short break feel most useful?'],
                ['Settling into sleep', 'I would like to wind down earlier in the evening.', 'A gentle evening cue can help. Would reading a few pages or putting your phone aside feel manageable?'],
                ['Making time for friends', 'I miss catching up with friends when schedules get full.', 'It makes sense to want that connection. A short message or a relaxed call could be a place to start.'],
                ['Learning to pause', 'I sometimes move from one task straight to the next.', 'You can make room for a brief pause between tasks. Try noticing your feet on the floor and taking one comfortable breath.'],
                ['Reflecting on small steps', 'I have been taking walks more often this week.', 'You noticed something that supports you. What do you enjoy about those walks?'],
            ];
            foreach ($conversations as $i => [$title, $question, $answer]) {
                $time = $today->copy()->subDays(25 - $i * 5)->setTime(19, 30);
                $conversation = $put(ChatConversation::class, "conversation-$i", [
                    'title' => $title, 'last_message_preview' => $answer, 'message_count' => 2,
                    'is_archived' => false, 'client_updated_at' => $time, 'created_at' => $time, 'updated_at' => $time,
                ]);
                foreach (['user' => $question, 'assistant' => $answer] as $role => $content) {
                    $messageTime = $time->copy()->addSeconds($role === 'assistant' ? 30 : 0);
                    $put(ChatMessage::class, "conversation-$i-$role", [
                        'conversation_id' => $conversation->id, 'role' => $role, 'content' => $content,
                        'status' => 'sent', 'is_crisis_flagged' => false,
                        'model_used' => $role === 'assistant' ? 'demo-scripted' : null,
                        'client_created_at' => $messageTime, 'created_at' => $messageTime, 'updated_at' => $messageTime,
                    ]);
                }
            }

            foreach ([[[0, 1, 0, 1, 0, 1, 0], 3, 'Minimal anxiety', 21], [[1, 1, 0, 1, 1, 1, 0], 5, 'Mild anxiety', 4]] as $i => [$answers, $score, $interpretation, $daysAgo]) {
                $time = $today->copy()->subDays($daysAgo)->setTime(18, 0);
                $put(QuizAttempt::class, "quiz-$i", [
                    'quiz_slug' => 'gad-7', 'quiz_title' => 'GAD-7 Anxiety Check', 'answers' => $answers,
                    'score' => $score, 'interpretation' => $interpretation, 'is_crisis_flagged' => false,
                    'is_deleted' => false, 'completed_at' => $time, 'client_created_at' => $time, 'created_at' => $time, 'updated_at' => $time,
                ]);
            }

            $put(SafetyPlan::class, 'safety-plan', [
                'warning_signs' => ['Feeling overstretched', 'Skipping my usual breaks'],
                'coping_strategies' => ['Slow comfortable breathing', 'Take a short walk', 'Write down one next step'],
                'distractions' => ['Listen to a familiar playlist', 'Make a warm drink'],
                'people' => [['name' => 'Sarah Chen', 'phone' => '+12025550143', 'relation' => 'Friend (fictional demo contact)']],
                'professionals' => [['name' => 'Sample Wellness Practice (fictional)', 'role' => 'Counsellor', 'phone' => '+12025550144']],
                'environment_steps' => ['Choose a quiet place', 'Keep my phone charged', 'Keep this plan easy to find'],
                'is_complete' => true, 'is_deleted' => false, 'last_updated_at' => now(), 'client_updated_at' => now(),
            ]);

            $notifications = [
                ['reminder', 'A moment to check in', 'Your daily check-in is ready whenever you are.'],
                ['achievement', 'Two weeks of check-ins', 'You have made time to notice how you feel for 14 days.'],
                ['journal', 'Make room for a thought', 'Your journal is here when you would like to write.'],
                ['general', 'Welcome to Cozy Health', 'Explore at your own pace.'],
                ['insight', 'Your weekly reflection', 'Your recent check-ins are ready to look back on.'],
            ];
            foreach ($notifications as $i => [$type, $title, $body]) {
                $time = $today->copy()->subDays($i + 1)->setTime(9, 0);
                $put(AppNotification::class, "notification-$i", [
                    'type' => $type, 'title' => $title, 'body' => $body, 'is_read' => true,
                    'read_at' => $time->copy()->addHour(), 'client_created_at' => $time, 'created_at' => $time, 'updated_at' => $time,
                ]);
            }
            SubscriptionStatus::withoutGlobalScopes()->updateOrCreate(['user_id' => $user->id], [
                'id' => SubscriptionStatus::withoutGlobalScopes()->where('user_id', $user->id)->value('id') ?? $id('subscription'),
                'plan' => 'pro', 'status' => 'active', 'started_at' => $today->copy()->subDays(29),
                'expires_at' => null, 'cancelled_at' => null, 'trial_ends_at' => null,
            ]);
        });
    }
}
