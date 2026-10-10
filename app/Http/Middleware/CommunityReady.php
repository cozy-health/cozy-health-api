<?php

namespace App\Http\Middleware;

use App\Models\FeatureFlag;
use Closure;
use Illuminate\Http\Request;

class CommunityReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('community.release_ready') && config('community.guidelines_version'), 503, 'Community is not available yet.');
        $flag = FeatureFlag::where('key', 'community')->first();
        abort_unless($flag?->enabled, 503, 'Community is temporarily unavailable.');

        return $next($request);
    }
}
