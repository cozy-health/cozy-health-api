<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SyncBatchRequest;
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
use App\Services\SyncRecordWriter;
use App\Services\UserPreferencesSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        'user_preferences' => UserProfile::class,
        'quiz_attempt' => QuizAttempt::class,
        'saved_article' => SavedArticle::class,
        'app_notification' => AppNotification::class,
        'subscription' => SubscriptionStatus::class,
        'subscription_status' => SubscriptionStatus::class,
    ];

    public function batch(SyncBatchRequest $request)
    {
        $request->validated();
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
                $results[] = [...$result, 'error' => 'Invalid operation format', 'error_code' => 'invalid_payload'];

                continue;
            }

            $modelClass = self::MODELS[$type] ?? null;
            if ($modelClass === null) {
                $results[] = [...$result, 'error' => 'Unknown type', 'error_code' => 'unknown_type'];

                continue;
            }

            $isCancellation = $action === 'cancel' && $modelClass === SubscriptionStatus::class;
            if (! in_array($action, ['upsert', 'delete'], true) && ! $isCancellation) {
                $results[] = [...$result, 'error' => 'Unknown action', 'error_code' => 'unknown_action'];

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
                $error = DB::transaction(function () use ($modelClass, $action, $data, $request, $isCancellation, $type) {
                    if ($type === 'user_preferences') {
                        app(UserPreferencesSync::class)->apply($request->user(), $action, $data);

                        return null;
                    }
                    if ($type === 'chat_message' && $action === 'upsert'
                        && ! ChatConversation::where('user_id', $request->user()->id)
                            ->where('id', $data['conversation_id'] ?? null)->exists()) {
                        return 'Conversation not found';
                    }
                    // Explicit ownership constraint also protects deletes and cancellations.
                    $query = $modelClass::where('user_id', $request->user()->id);
                    if ($action === 'upsert') {
                        return app(SyncRecordWriter::class)->upsert($modelClass, $type, $request->user(), $data);
                    } elseif ($action === 'delete') {
                        app(SyncRecordWriter::class)->delete($modelClass, $type, $request->user(), $data['id']);
                    } elseif ($isCancellation) {
                        $subscription = $query->first();
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
                if ($error === null && $type === 'quiz_attempt' && $action === 'upsert') {
                    $result['record'] = QuizAttempt::whereKey($data['id'])->first()?->toArray();
                }
                $results[] = $error === null
                    ? [...$result, 'success' => true]
                    : [...$result, 'error' => $error, 'error_code' => $error === 'Conversation not found' ? 'dependency_missing' : $error];
            } catch (ValidationException $e) {
                $results[] = [...$result, 'error' => $type === 'user_preferences' ? 'Invalid preferences payload' : 'Invalid sync payload', 'error_code' => 'invalid_payload', 'errors' => $e->errors()];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $code = match ($e->getStatusCode()) {
                    503 => 'feature_paused', 429 => 'rate_limited', 409 => 'conflict',
                    410 => 'gone', 404 => 'definition_unavailable', default => 'invalid_payload',
                };
                $results[] = [...$result, 'error' => 'Assessment could not be validated', 'error_code' => $code];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $results[] = [...$result, 'error' => 'Definition unavailable', 'error_code' => 'definition_unavailable'];
            } catch (Throwable $e) {
                report($e);
                $results[] = [...$result, 'error' => 'Unable to apply sync operation', 'error_code' => 'database'];
            }
        }

        return $this->success(['results' => $results]);
    }
}
