<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CommunityPrivacy
{
    public function removeAccount(int $userId): void
    {
        // Keep minimal moderation history without retaining deleted accounts'
        // names, IDs, post text or report details inside JSON snapshots.
        DB::table('community_reports')->where(function ($query) use ($userId) {
            $query->where('reporter_id', $userId)
                ->orWhere(fn ($q) => $q->where('target_type', 'post')->whereIn('target_id',
                    DB::table('community_posts')->where('user_id', $userId)->select('id')))
                ->orWhere(fn ($q) => $q->where('target_type', 'comment')->whereIn('target_id',
                    DB::table('community_comments')->where('user_id', $userId)->select('id')));
        })->update(['details' => null, 'snapshot' => json_encode(['account_deleted' => true]), 'updated_at' => now()]);
    }
}
