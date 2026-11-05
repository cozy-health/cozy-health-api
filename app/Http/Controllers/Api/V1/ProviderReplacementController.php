<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Provider\ProviderReplacementService;
use Illuminate\Http\Request;

final class ProviderReplacementController extends Controller
{
    public function store(Request $request, ProviderReplacementService $service)
    {
        $data = $request->validate(['original_link_id' => ['required', 'integer', 'min:1'],
            'provider_email' => ['required', 'email', 'max:190'], 'request_id' => ['required', 'uuid']]);
        [$link, $created] = $service->start($request, $data);

        return response()->json(['data' => $service->payload($link)], $created ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $requestId, ProviderReplacementService $service)
    {
        $service->cancel($request, $requestId);

        return response()->json(['message' => 'Replacement invitation cancelled. Your original connection is unchanged.']);
    }
}
