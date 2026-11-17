<?php

namespace App\Http\Middleware;

use App\Services\CommunityAvailability;
use Closure;
use Illuminate\Http\Request;

class CommunityReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(CommunityAvailability::class)->enabled(), 503, 'Community is temporarily unavailable.');

        return $next($request);
    }
}
