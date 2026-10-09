<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceSecurityController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['detected' => ['required', 'accepted']]);
        DB::transaction(function () {
            $key = ['day' => now()->toDateString(), 'event' => 'device_integrity_warning'];
            DB::table('security_signal_counts')->insertOrIgnore([...$key, 'count' => 0]);
            DB::table('security_signal_counts')->where($key)->increment('count');
        });
        return response()->json(['success' => true]);
    }
}
