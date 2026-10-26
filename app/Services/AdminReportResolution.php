<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AdminReportResolution
{
    /** Call inside the moderation transaction, after locking the target content. */
    public static function resolvePending(int $actorId, string $targetType, string $targetId, string $decision): void
    {
        $ids = DB::table('community_reports')->where('target_type', $targetType)
            ->where('target_id', $targetId)->where('status', 'pending')
            ->orderBy('id')->lockForUpdate()->pluck('id')->all();
        DB::table('community_reports')->whereIn('id', $ids)
            ->update(['status' => 'resolved', 'resolved_at' => now('UTC'), 'updated_at' => now()]);
        foreach ($ids as $id) {
            AdminAudit::record($actorId, $decision, 'community-reports', (string) $id, ['status', 'resolved_at']);
        }
    }
}
