<?php

namespace App\Services;

use App\Jobs\SendCommunityModerationNotice;
use App\Models\{AppNotification, User};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class CommunityModeration
{
    public function lockUser(int $id): User
    {
        return User::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function active(int $user): ?object
    {
        return DB::table('community_decisions')->where('user_id', $user)->where('kind', 'ban')->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
    }

    public function expire(int $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lockUser($user);
            $rows = DB::table('community_decisions')->where('user_id', $user)->where('kind', 'ban')->where('status', 'active')
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                DB::table('community_decisions')->where('id', $row->id)->update(['status' => 'expired', 'version' => (string) Str::uuid(), 'updated_at' => now()]);
                $this->notice($user, 'ban-expired:'.$row->id, 'Community restriction expired', 'Your community participation restriction has expired.');
            }
        }, 3);
    }

    public function ban(int $actor, int $user, string $duration, string $reason): object
    {
        return DB::transaction(function () use ($actor, $user, $duration, $reason) {
            $owner = $this->lockUser($user);
            abort_unless($owner->role === 'user' && $owner->is_active, 422, 'Choose an active patient account.');
            $this->expire($user);
            abort_if($this->active($user), 409, 'This account already has an active community restriction.');
            $id = (string) Str::uuid();
            $expiry = match ($duration) { '24h' => now()->addDay(), '7d' => now()->addDays(7), '30d' => now()->addDays(30), 'permanent' => null };
            DB::table('community_decisions')->insert(['id' => $id, 'user_id' => $user, 'actor_id' => $actor, 'kind' => 'ban',
                'reason' => $reason, 'status' => 'active', 'version' => (string) Str::uuid(), 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($actor, 'ban', 'community-decisions', $id, ['reason', 'expires_at']);
            $this->notice($user, 'ban-created:'.$id, 'Community participation restricted', $reason.' '.($expiry ? 'Expires '.$expiry->toIso8601String().'.' : 'This restriction remains until lifted.').' Reading, reporting and appeals remain available.');
            return DB::table('community_decisions')->where('id', $id)->first();
        }, 3);
    }

    public function lift(int $actor, string $id, string $version, string $reason): void
    {
        $snapshot = DB::table('community_decisions')->where('id', $id)->where('kind', 'ban')->firstOrFail();
        DB::transaction(function () use ($actor, $snapshot, $version, $reason) {
            $this->lockUser($snapshot->user_id);
            $row = DB::table('community_decisions')->where('id', $snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === $version && $row->status === 'active' && (!$row->expires_at || now()->lt($row->expires_at)), 409, 'Restriction changed or expired. Reload first.');
            DB::table('community_decisions')->where('id', $row->id)->update(['status' => 'lifted', 'version' => (string) Str::uuid(), 'updated_at' => now()]);
            AdminAudit::record($actor, 'lift', 'community-decisions', $row->id, ['status']);
            $this->notice($row->user_id, 'ban-lifted:'.$row->id, 'Community restriction lifted', $reason);
        }, 3);
    }

    /** Called inside existing content-before-report locking transaction. No user lock is taken here. */
    public function removed(int $actor, string $kind, string $id, string $reason): void
    {
        $row = DB::table('community_'.($kind === 'post' ? 'posts' : 'comments'))->where('id', $id)->firstOrFail();
        $version = AdminContentVersion::token($row);
        if (DB::table('community_decisions')->where('kind', $kind)->where('target_id', $id)->where('content_version', $version)->exists()) return;
        $decision = (string) Str::uuid();
        DB::table('community_decisions')->where('kind', $kind)->where('target_id', $id)->where('status', 'active')->update(['status' => 'superseded', 'version' => (string) Str::uuid(), 'updated_at' => now()]);
        DB::table('community_decisions')->insert(['id' => $decision, 'user_id' => $row->user_id, 'actor_id' => $actor, 'kind' => $kind,
            'target_id' => $id, 'content_version' => $version, 'reason' => $reason, 'status' => 'active', 'version' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->notice($row->user_id, 'content-removed:'.$decision, 'Community content removed', $reason.' You can review this decision and appeal in Community moderation.');
    }

    public function appeal(int $user, string $id, string $message): object
    {
        return DB::transaction(function () use ($user, $id, $message) {
            $this->lockUser($user);
            $decision = DB::table('community_decisions')->where('id', $id)->where('user_id', $user)->lockForUpdate()->firstOrFail();
            $existing = DB::table('community_appeals')->where('decision_id', $id)->first();
            if ($existing) return $existing;
            abort_unless($decision->status === 'active' && ($decision->kind !== 'ban' || !$decision->expires_at || now()->lt($decision->expires_at)), 409, 'This decision is no longer active.');
            $appeal = (string) Str::uuid();
            DB::table('community_appeals')->insert(['id' => $appeal, 'decision_id' => $id, 'user_id' => $user, 'message' => Crypt::encryptString($message), 'status' => 'pending', 'version' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
            $this->notice($user, 'appeal-submitted:'.$appeal, 'Community appeal submitted', 'Your appeal is waiting for an admin review. No response time is promised.');
            return DB::table('community_appeals')->where('id', $appeal)->first();
        }, 3);
    }

    public function review(int $actor, string $id, string $version, string $outcome, string $reason, bool $confirm): void
    {
        $snapshot = DB::table('community_appeals')->where('id', $id)->firstOrFail();
        DB::transaction(function () use ($actor, $snapshot, $version, $outcome, $reason, $confirm) {
            $this->lockUser($snapshot->user_id);
            $decision = DB::table('community_decisions')->where('id', $snapshot->decision_id)->firstOrFail();
            // Match existing moderation order: content, then decision/appeal, then reports.
            $table = $decision->kind === 'ban' ? null : 'community_'.($decision->kind === 'post' ? 'posts' : 'comments');
            $content = $table ? DB::table($table)->where('id', $decision->target_id)->whereNull('deleted_at')->lockForUpdate()->first() : null;
            $decision = DB::table('community_decisions')->where('id', $decision->id)->lockForUpdate()->firstOrFail();
            $appeal = DB::table('community_appeals')->where('id', $snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($appeal->status === 'pending' && $appeal->version === $version, 409, 'Appeal changed. Reload first.');
            if ($outcome === 'accepted') {
                abort_unless($decision->status === 'active', 409, 'Decision has been superseded or lifted.');
                if ($table) {
                    abort_unless($content && $content->status === 'removed' && hash_equals($decision->content_version, AdminContentVersion::token($content)), 409, 'Content changed or was deleted. It cannot be restored by this appeal.');
                    abort_unless($confirm, 422, 'Explicitly confirm republishing this content.');
                    DB::table($table)->where('id', $content->id)->update(['status' => 'published', 'updated_at' => now()]);
                    AdminReportResolution::resolvePending($actor, $decision->kind, $content->id, 'publish');
                } else {
                    abort_if($decision->expires_at && now()->gte($decision->expires_at), 409, 'Restriction already expired.');
                    $this->notice($decision->user_id, 'ban-lifted:'.$decision->id, 'Community restriction lifted', $reason);
                }
                DB::table('community_decisions')->where('id', $decision->id)->update(['status' => 'lifted', 'version' => (string) Str::uuid(), 'updated_at' => now()]);
            }
            DB::table('community_appeals')->where('id', $appeal->id)->update(['status' => $outcome, 'outcome' => Crypt::encryptString($reason), 'reviewer_id' => $actor, 'version' => (string) Str::uuid(), 'updated_at' => now()]);
            AdminAudit::record($actor, 'appeal-'.$outcome, 'community-appeals', $appeal->id, ['status', 'outcome']);
            $this->notice($appeal->user_id, 'appeal-decided:'.$appeal->id, 'Community appeal '.$outcome, $reason);
        }, 3);
    }

    public function notice(int $user, string $event, string $title, string $body): void
    {
        $owner = User::find($user);
        if (!$owner) return;
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'cozy-moderation:'.$event)->toString();
        if (!DB::table('community_moderation_notices')->insertOrIgnore(['id' => $id, 'user_id' => $user,
            'email_key' => hash('sha256', strtolower(trim($owner->email))), 'payload' => Crypt::encryptString(json_encode([$title, $body], JSON_THROW_ON_ERROR)),
            'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()])) return;
        AppNotification::withoutGlobalScope('user')->create(['id' => $id, 'user_id' => $user, 'type' => 'community_moderation', 'title' => $title,
            'body' => $body, 'deep_link' => '/community/moderation', 'data' => ['event' => $event], 'is_read' => false, 'client_created_at' => now()]);
        // Durable outbox scheduler dispatches after the enclosing transaction commits.
    }

    public function tick(): void
    {
        DB::table('community_decisions')->where('kind', 'ban')->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->select('user_id')->distinct()->orderBy('user_id')->chunk(100, function ($rows) { foreach ($rows as $row) if (User::whereKey($row->user_id)->exists()) $this->expire($row->user_id); });
        DB::table('community_moderation_notices')->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 3)
            ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->chunk(100, function ($rows) {
                foreach ($rows as $row) SendCommunityModerationNotice::dispatch($row->id);
            });
    }
}
