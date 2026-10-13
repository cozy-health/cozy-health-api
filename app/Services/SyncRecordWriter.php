<?php

namespace App\Services;

use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncRecordWriter
{
    public function upsert(string $modelClass, string $type, User $user, array $data): ?string
    {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($type === 'quiz_attempt') {
            if (empty($data['definition_version'])) app(AssessmentService::class)->submitLegacy($user, $data);
            else app(AssessmentService::class)->submit($user, $data);
            return null;
        }
        if ($type === 'user_profile' && array_key_exists('username', $data)) {
            $data['username'] = app(UsernameService::class)->validate($user, $data['username']);
        }
        if ($type === 'user_profile') {
            \Illuminate\Support\Facades\Validator::make($data, [
                ...\App\Services\Push\ReminderSettings::rules(),
                'notifications_master' => 'sometimes|boolean',
                'daily_checkin_enabled' => 'sometimes|boolean',
                'daily_checkin_time' => 'sometimes|date_format:H:i',
                'journal_reminder_enabled' => 'sometimes|boolean',
            ])->validate();
        }
        $query = $modelClass::where('user_id', $user->id);
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
        if ($softDeletes) {
            $query->withTrashed();
        }
        $singleton = in_array($type, ['user_profile', 'safety_plan', 'subscription', 'subscription_status'], true);
        if ($singleton) {
            $record = $query->first();
        } elseif ($type === 'saved_article') {
            $record = $query->where('article_id', $data['article_id'] ?? $data['id'])->first();
        } else {
            $record = $query->where('id', $data['id'])->first();
        }
        if (! $record && ((in_array($type, ['user_profile', 'safety_plan'], true) && ! Str::isUuid($data['id'])) ||
            ($type === 'saved_article' && strlen($data['id']) > 36))) {
            $data['id'] = (string) Str::uuid();
        }
        if (! $record && $modelClass::withoutGlobalScope('user')->where('id', $data['id'])->exists()) {
            return 'ownership_conflict';
        }
        if ($type === 'saved_article') {
            // Legacy mobile bookmarks omit these non-null metadata columns.
            $data['article_slug'] ??= $record?->article_slug ?? (string) ($data['article_id'] ?? '');
            $data['category'] ??= $record?->category ?? '';
            $data['read_time_minutes'] ??= $record?->read_time_minutes ?? 0;
        }
        if ($type === 'chat_conversation') {
            $data['title'] ??= 'Conversation';
        }
        if ($type === 'safety_plan' && ! isset($data['client_updated_at'])) {
            $data['client_updated_at'] = $data['last_updated_at'] ?? null;
        }
        $incomingTime = null;
        if (isset($data['client_updated_at'])) {
            try {
                $incomingTime = Carbon::parse($data['client_updated_at']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['client_updated_at' => ['Invalid update time.']]);
            }
        }
        if ($record && $incomingTime && $record->client_updated_at && $incomingTime->lt($record->client_updated_at)) {
            return null;
        }
        if ($record && $softDeletes && $record->trashed()) {
            if ($type !== 'saved_article' && (! $incomingTime || $incomingTime->lte($record->deleted_at))) {
                return null;
            }
            $record->restore();
        }
        if ($record) {
            unset($data['id']);
            $record->fill($data)->save();
        } else {
            $record = $modelClass::create($data);
        }
        $event = ['mood_entry' => 'mood_logged', 'journal_entry' => 'journal_created', 'quiz_attempt' => 'quiz_completed'][$type] ?? null;
        if ($record->wasRecentlyCreated && $event && ! ($data['is_deleted'] ?? false)) {
            app(AnalyticsService::class)->track($event);
        }

        return null;
    }

    public function delete(string $modelClass, string $type, User $user, string $id): void
    {
        if ($type === 'quiz_attempt') {
            app(QuizSharingService::class)->deleteAttempt($user->id, $id);
            return;
        }
        $query = $modelClass::where('user_id', $user->id);
        if ($type === 'saved_article') {
            $query->where(fn ($query) => $query->where('id', $id)->orWhere('article_id', $id));
        } elseif (! in_array($type, ['user_profile', 'safety_plan'], true)) {
            $query->where('id', $id);
        }
        $query->delete();
    }
}
