<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class AdminQueueHealthController extends Controller
{
    public function __invoke()
    {
        $connection = config('queue.connections.database.connection');
        $table = config('queue.connections.database.table', 'jobs');
        $backlog = DB::connection($connection)->table($table)
            ->selectRaw('COUNT(*) AS depth, MIN(created_at) AS oldest')->first();
        $depth = (int) $backlog->depth;
        $age = $backlog->oldest === null ? 0 : max(0, now()->timestamp - (int) $backlog->oldest);

        return response()->json([
            'depth' => $depth,
            'oldest_job_age_seconds' => $age,
            'warning' => $depth > 100 || $age > 3600,
        ])->header('Cache-Control', 'no-store, private');
    }
}
