<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AppNotification;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\QuizAttempt;
use App\Models\SafetyPlan;
use App\Models\SavedArticle;
use App\Models\SubscriptionStatus;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncController extends ApiController
{
    private const MODELS = [
        'mood_entry' => MoodEntry::class,
        'journal_entry' => JournalEntry::class,
        'chat_conversation' => ChatConversation::class,
        'chat_message' => ChatMessage::class,
        'safety_plan' => SafetyPlan::class,
        'user_profile' => UserProfile::class,
        'quiz_attempt' => QuizAttempt::class,
        'saved_article' => SavedArticle::class,
        'app_notification' => AppNotification::class,
        'subscription' => SubscriptionStatus::class,
        'subscription_status' => SubscriptionStatus::class,
    ];

    public function batch(Request $request)
    {
        $request->validate(['operations' => ['sometimes', 'array']]);
        $results = [];

        foreach ($request->input('operations', []) as $operation) {
            $op = is_array($operation) ? $operation : [];
            $data = $op['data'] ?? [];
            $type = $op['type'] ?? null;
            $action = $op['action'] ?? null;
            $id = is_array($data) ? ($data['id'] ?? $op['id'] ?? null) : ($op['id'] ?? null);
            $clientId = $op['client_operation_id'] ?? $op['client_id'] ?? $id;
            $result = [
                'client_operation_id' => is_scalar($clientId) ? (string) $clientId : null,
                'client_id' => is_scalar($clientId) ? (string) $clientId : null,
                'type' => is_string($type) ? $type : null,
                'id' => is_scalar($id) ? (string) $id : null,
                'success' => false,
            ];

            if (! is_string($type) || ! is_string($action) || ! is_array($data)
                || ! is_scalar($id) || (string) $id === '') {
                $results[] = [...$result, 'error' => 'Invalid operation format'];

                continue;
            }

            $modelClass = self::MODELS[$type] ?? null;
            if ($modelClass === null) {
                $results[] = [...$result, 'error' => 'Unknown type'];

                continue;
            }

            $isCancellation = $action === 'cancel' && $modelClass === SubscriptionStatus::class;
            if (! in_array($action, ['upsert', 'delete'], true) && ! $isCancellation) {
                $results[] = [...$result, 'error' => 'Unknown action'];

                continue;
            }

            $data['id'] = (string) $id;
            $data['user_id'] = $request->user()->id;
            if (in_array($type, ['journal_entry', 'mood_entry'], true) && $action === 'upsert') {
                $data['is_crisis_flagged'] = filter_var(
                    $data['is_crisis_flagged'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            try {
                $error = DB::transaction(function () use ($modelClass, $action, $data, $request, $isCancellation) {
                    // Explicit ownership constraint also protects deletes and cancellations.
                    $query = $modelClass::where('user_id', $request->user()->id);
                    if ($action === 'upsert') {
                        $query->updateOrCreate(['id' => $data['id']], $data);
                    } elseif ($action === 'delete') {
                        $query->where('id', $data['id'])->delete();
                    } elseif ($isCancellation) {
                        $subscription = $query->where('id', $data['id'])->first();
                        if (! $subscription) {
                            return 'Subscription not found';
                        }
                        $subscription->update([
                            'status' => 'inactive',
                            'cancelled_at' => $subscription->cancelled_at ?? now(),
                        ]);
                    }

                    return null;
                });
                $results[] = $error === null
                    ? [...$result, 'success' => true]
                    : [...$result, 'error' => $error];
            } catch (Throwable $e) {
                report($e);
                $results[] = [...$result, 'error' => 'Unable to apply sync operation'];
            }
        }

        return $this->success(['results' => $results]);
    }
}
