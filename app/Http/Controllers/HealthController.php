<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class HealthController extends Controller
{
    public function __invoke()
    {
        $state = ['db' => 'down', 'queue' => 'down', 'cache' => 'down'];
        try { DB::select('SELECT 1'); $state['db'] = 'ok'; } catch (Throwable) {}
        try { $state['queue'] = DB::table('jobs')->count() < 1000 ? 'ok' : 'backlogged'; } catch (Throwable) {}
        $key = 'health:'.Str::uuid();
        try {
            Cache::put($key, 'ok', 10);
            $state['cache'] = Cache::get($key) === 'ok' ? 'ok' : 'down';
            Cache::forget($key);
        } catch (Throwable) {}
        $settings = [];
        try {
            if (Schema::hasTable('settings')) {
                $settings = DB::table('settings')->pluck('value', 'key')->map(fn ($value) => json_decode($value, true))->all();
            }
        } catch (Throwable) {}
        $status = $state['db'] === 'down' ? 'down' : (count(array_filter($state, fn ($value) => $value !== 'ok')) ? 'degraded' : 'ok');
        return response()->json([
            'status' => $status, ...$state,
            'maintenance_mode' => $settings['maintenance_mode'] ?? false,
            'min_app_version' => $settings['min_app_version'] ?? '1.0.0',
            'server_time' => now()->toIso8601String(),
        ], $status === 'down' ? 503 : 200);
    }
}
