<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreakController extends Controller
{
    public function show(Request $request, StreakService $streaks): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $streaks->snapshot($request->user())]);
    }
}
