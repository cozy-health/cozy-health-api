<?php
namespace App\Http\Middleware;

use App\Services\DevelopmentBackupControl;
use Closure;
use Illuminate\Http\Request;

final class TrackDevelopmentBackupActivity
{
    public function handle(Request $request, Closure $next)
    {
        $control = app(DevelopmentBackupControl::class);
        if (! $control->enabled()) return $next($request);
        // Runs before Laravel's maintenance middleware: the first request after
        // expiry can release only backup-owned maintenance, even if a job died.
        $control->watchdog();
        if (app()->isDownForMaintenance()) return $next($request);
        $id = $control->beginActivity();
        try { return $next($request); }
        finally { $control->endActivity($id); }
    }
}
