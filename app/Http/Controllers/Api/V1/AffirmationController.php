<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AffirmationAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffirmationController extends Controller
{
    public function today(Request $request, AffirmationAssignmentService $assignments): JsonResponse
    {
        $data = $assignments->current($request->user());

        return response()->json([
            'success' => true,
            'message' => $data ? "Today's affirmation retrieved." : 'No affirmations available.',
            'data' => $data,
        ]);
    }
}
