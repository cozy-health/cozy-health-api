<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PlanAccess;
use Closure;
use Illuminate\Http\Request;

final class EnforcePlanAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() instanceof User || $request->user()->role !== 'user' || !config('plan_access.enabled')) return $next($request);
        $path = preg_replace('#^api/v1/#', '', $request->path());
        $write = in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);
        $access = app(PlanAccess::class);
        $feature = null;
        if ($path === 'audio-attachments' && $write) $feature = 'voice';
        if ($path === 'mood-entries' && $write) $feature = 'mood_checkin';
        if (preg_match('#^journal-entries(?:/([^/]+))?$#', $path, $matches) && $write) {
            $existing = isset($matches[1]) ? \App\Models\JournalEntry::where('user_id', $request->user()->id)->find($matches[1]) : null;
            $access->journal($request->user(), $request->all(), $existing);
        }
        if (in_array($path, ['provider/request', 'provider/verify'], true) && $write) $feature = 'provider_connect';
        if (preg_match('#^me/provider/[^/]+/consent$#', $path) && $write && $request->input('value') === true) $feature = 'provider_connect';
        if (preg_match('#^provider-conversations/[^/]+/messages$#', $path) && $write) $feature = 'provider_chat';
        if (preg_match('#^provider-conversations/[^/]+/journal-shares$#', $path) && $write) $feature = 'journal_share';
        if (preg_match('#^wellness/challenges/[^/]+/(enroll|complete)$#', $path) && $write) $feature = 'challenges';
        if (($path === 'quiz-attempts' && $write) || ($write && preg_match('#^quizzes/(?:[^/]+/(?:start|submit)|submit-offline)$#', $path))) $feature = 'assessments';
        if ($write && preg_match('#^quizzes/attempts/[^/]+/(share|friends|invitations)$#', $path)) $feature = 'assessments';
        if (str_starts_with($path, 'community/')) {
            // Reports, blocking, leaving and deleting one's content remain possible after expiry.
            if ($request->isMethod('GET') && $path !== 'community/blocked') $feature = 'community_read';
            elseif ($write && !preg_match('#/(report|block)$#', $path)) $feature = 'community_participate';
        }
        if ($feature) $access->require($request->user(), $feature);
        return $next($request);
    }
}
