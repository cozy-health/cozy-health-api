<?php

namespace App\Http\Middleware;

use App\Services\CommunityModeration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityParticipation
{
    public function handle(Request $request, Closure $next)
    {
        $path = substr($request->path(), strlen('api/v1/community/'));
        $restricted = !$request->isMethod('GET') && !$request->isMethod('HEAD') && !$request->isMethod('DELETE')
            && !preg_match('#^(decisions/[^/]+/appeal|posts/[^/]+/report|comments/[^/]+/report|users/[^/]+/block)$#', $path);
        if (!$restricted) return $next($request);
        return DB::transaction(function () use ($request, $next) {
            $moderation = app(CommunityModeration::class);
            $moderation->lockUser($request->user()->id);
            $ban = $moderation->active($request->user()->id);
            if ($ban) return response()->json(['message' => 'Community participation is restricted. Reading, reporting and appeals remain available.', 'code' => 'community_banned', 'expires_at' => $ban->expires_at], 403);
            return $next($request);
        }, 3);
    }
}
