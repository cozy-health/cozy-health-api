<?php
namespace App\Services;

use App\Jobs\SendCommunityPush;
use App\Models\{AppNotification, User, UserProfile};
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class CommunityNotifications
{
    public function create(string $type, object $post, int $actor, ?object $comment = null): void
    {
        $owner = (int) $post->user_id;
        if ($owner === $actor || $post->status !== 'published' || $post->deleted_at
            || ($comment && ($comment->status !== 'published' || $comment->deleted_at))) return;
        $user = User::find($owner);
        $profile = UserProfile::withoutGlobalScope('user')->where('user_id', $owner)->first();
        if (! $user?->is_active || $user->is_demo || ! $this->categoryAllowed($profile, $type)
            || $this->blocked($owner, $actor)) return;
        $event = $type.':'.($comment?->id ?? $post->id).':'.$actor.':'.$owner;
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'cozy-community:'.$event)->toString();
        DB::transaction(function () use ($id, $type, $post, $comment, $actor, $owner) {
            if (! DB::table('community_notification_events')->insertOrIgnore(['notification_id' => $id,
                'actor_id' => $actor, 'post_id' => $post->id, 'comment_id' => $comment?->id,
                'created_at' => now(), 'updated_at' => now()])) return;
            AppNotification::create(['id' => $id, 'user_id' => $owner, 'type' => $type,
                'title' => $type === 'reply' ? 'A new reply' : 'A new like',
                'body' => $type === 'reply' ? 'Someone replied to your community post.' : 'Someone liked your community post.',
                'deep_link' => '/community', 'data' => ['post_id' => $post->id],
                'is_read' => false, 'client_created_at' => now()]);
            SendCommunityPush::dispatch($id)->afterCommit();
        });
    }

    public function categoryAllowed(?UserProfile $profile, string $type): bool
    {
        $settings = $profile?->reminder_settings ?? [];
        return ($profile?->notifications_master ?? true)
            && ($type === 'reply' ? ($profile?->comments_notifications ?? true) : ($settings['community_likes_enabled'] ?? true));
    }

    public function blocked(int $owner, int $actor): bool
    {
        return DB::table('community_blocks')->where(fn ($q) => $q->where('user_id', $owner)->where('blocked_user_id', $actor))
            ->orWhere(fn ($q) => $q->where('user_id', $actor)->where('blocked_user_id', $owner))->exists();
    }

    public function available(AppNotification $notification): bool
    {
        $event = DB::table('community_notification_events')->where('notification_id', $notification->id)->first();
        if (! $event || $this->blocked($notification->user_id, $event->actor_id)) return false;
        if (! User::whereKey($event->actor_id)->where('is_active', true)->exists()) return false;
        $post = DB::table('community_posts')->where('id', $event->post_id)->whereNull('deleted_at')->where('status', 'published')->first();
        if (! $post) return false;
        if ($post->group_id && (! DB::table('community_groups')->where('id', $post->group_id)->where('is_active', true)->exists()
            || ! DB::table('community_memberships')->where('group_id', $post->group_id)->where('user_id', $notification->user_id)->exists())) return false;
        if ($event->comment_id && ! DB::table('community_comments')->where('id', $event->comment_id)->whereNull('deleted_at')->where('status', 'published')->exists()) return false;
        if ($notification->type === 'like' && ! DB::table('community_reactions')->where('user_id', $event->actor_id)
            ->where('target_type', 'post')->where('target_id', $post->id)->exists()) return false;
        return true;
    }
}
