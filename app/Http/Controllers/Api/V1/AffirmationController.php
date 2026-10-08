<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affirmation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffirmationController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        $today = $request->user()->localToday()->toDateString();
        $active = Affirmation::query()->where('is_active', true)->orderBy('id')->get(['body']);
        if ($active->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No affirmations available.',
                'data' => null,
            ]);
        }

        // A local calendar day ordinal avoids DST drift; rotation prevents adjacent-day hash collisions.
        $day = intdiv(CarbonImmutable::parse($today, 'UTC')->timestamp, 86400);
        $index = (crc32((string) $request->user()->id) + $day) % $active->count();

        return response()->json([
            'success' => true,
            'message' => "Today's affirmation retrieved.",
            'data' => ['body' => $active[$index]->body, 'date' => $today],
        ]);
    }
}
