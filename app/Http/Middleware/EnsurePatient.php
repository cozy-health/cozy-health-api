<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsurePatient
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user instanceof User) $user->refresh();
        abort_unless($user instanceof User && $user->is_active && $user->role === 'user', 403);
        return $next($request);
    }
}
