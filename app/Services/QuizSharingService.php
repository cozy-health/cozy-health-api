<?php
namespace App\Services;

use App\Models\{AppNotification, ProviderClientLink, QuizAttempt, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuizSharingService
{
    public function owner(int $owner, string $id, bool $validated = true): QuizAttempt
    {
        $attempt = QuizAttempt::withoutGlobalScope('user')->where('user_id', $owner)->where('is_deleted', false)->findOrFail($id);
        abort_if($validated && ! $attempt->validated_at, 409, 'Connect to validate this result before sharing.');
        return $attempt;
    }

    public function summary(QuizAttempt $attempt): array
    {
        return ['id' => $attempt->id, 'quiz_slug' => $attempt->quiz_slug, 'quiz_title' => $attempt->quiz_title,
            'score' => $attempt->score, 'maximum_score' => $attempt->maximum_score, 'interpretation' => $attempt->interpretation,
            'completed_at' => $attempt->completed_at, 'disclaimer' => $attempt->result_snapshot['disclaimer'] ?? '',
            'recommendation' => $attempt->result_snapshot['recommendation'] ?? ''];
    }

    public function providerQuery(int $providerId, int $owner, int $linkId)
    {
        return DB::table('quiz_shares')->where('provider_id', $providerId)->where('user_id', $owner)
            ->where('link_id', $linkId)->whereNull('revoked_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('providers')->whereColumn('providers.id', 'quiz_shares.provider_id')->where('providers.is_active', true)->where('providers.is_verified', true));
    }

    public function filterProvider($query, ProviderClientLink $link)
    {
        return $query->whereNotNull('validated_at')->whereIn('id', $this->providerQuery($link->provider_id, $link->user_id, $link->id)->select('quiz_attempt_id'));
    }

    public function friend(int $owner, string $attemptId, int $recipient): object
    {
        return DB::transaction(function () use ($owner, $attemptId, $recipient) {
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $this->owner($owner, $attemptId);
            abort_if($owner === $recipient, 422, 'Choose another recipient.');
            User::whereKey($recipient)->where('is_active', true)->where('is_demo', false)->firstOrFail();
            $existing = DB::table('quiz_friend_shares')->where('quiz_attempt_id', $attemptId)->where('recipient_id', $recipient)->lockForUpdate()->first();
            if ($existing && ! $existing->revoked_at) return $existing;
            $id = $existing?->id ?? (string) Str::uuid();
            DB::table('quiz_friend_shares')->updateOrInsert(['id' => $id], ['quiz_attempt_id' => $attemptId,
                'user_id' => $owner, 'recipient_id' => $recipient, 'revoked_at' => null, 'dismissed_at' => null,
                'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(function () use ($recipient, $id) {
                try {
                    $notification = AppNotification::withoutGlobalScope('user')->create(['id' => (string) Str::uuid(), 'user_id' => $recipient,
                        'type' => 'quiz_share', 'title' => 'A result was shared with you', 'body' => 'Open Cozy Health to view it.',
                        'deep_link' => '/quiz-shared/'.$id, 'data' => ['user_id' => (string) $recipient, 'share_id' => $id],
                        'is_read' => false, 'client_created_at' => now()]);
                    \App\Jobs\SendQuizSharePush::dispatch($notification->id);
                } catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Quiz share notification unavailable.'); }
            });
            return DB::table('quiz_friend_shares')->where('id', $id)->first();
        });
    }

    public function revoke(int $owner, string $attempt, string $kind, string $id): void
    {
        DB::transaction(function () use ($owner, $attempt, $kind, $id) {
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $this->owner($owner, $attempt, false);
            if ($kind === 'provider') ProviderClientLink::where('user_id', $owner)->lockForUpdate()->get();
            $table = match ($kind) { 'friend' => 'quiz_friend_shares', 'provider' => 'quiz_shares', 'invitation' => 'quiz_invitations', default => abort(422) };
            $query = DB::table($table)->where('user_id', $owner)->where('quiz_attempt_id', $attempt)->where('id', $id);
            abort_unless($query->exists(), 404);
            $query->update(['revoked_at' => now(), 'updated_at' => now()]);
        });
    }

    public function deleteAttempt(int $owner, string $id): void
    {
        DB::transaction(function () use ($owner, $id) {
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            // Lock the relationship first, matching provider report reads.
            ProviderClientLink::where('user_id', $owner)->lockForUpdate()->get();
            $attempt = QuizAttempt::withoutGlobalScope('user')->withTrashed()->where('user_id', $owner)->whereKey($id)->lockForUpdate()->first();
            if (! $attempt) {
                abort_if(QuizAttempt::withoutGlobalScope('user')->withTrashed()->whereKey($id)->exists(), 404);
                // Retain a tombstone for a queued offline attempt never submitted.
                $attempt = QuizAttempt::create(['id' => $id, 'user_id' => $owner, 'quiz_slug' => 'deleted', 'quiz_title' => '',
                    'answers' => [], 'score' => 0, 'interpretation' => '', 'is_crisis_flagged' => false,
                    'completed_at' => now(), 'client_created_at' => now(), 'is_deleted' => true]);
            }
            foreach (['quiz_friend_shares', 'quiz_shares', 'quiz_invitations'] as $table) {
                DB::table($table)->where('quiz_attempt_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            $attempt->update(['is_deleted' => true]);
            if (! $attempt->trashed()) $attempt->delete();
        });
    }
}
