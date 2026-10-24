<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Provider\JournalSharingService;
use Illuminate\Http\Request;

class JournalSharingController extends Controller
{
    public function preview(Request $request, string $linkId, JournalSharingService $shares)
    {
        abort_unless($request->user() instanceof User, 403);
        $data = $request->validate(['journal_id' => 'required|uuid']);
        return response()->json($shares->preview($request->user(), $linkId, $data['journal_id']))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $linkId, JournalSharingService $shares)
    {
        abort_unless($request->user() instanceof User, 403);
        $data = $request->validate(['journal_id' => 'required|uuid', 'expected_revision' => 'required|string|size:64|regex:/^[a-f0-9]+$/',
            'client_id' => 'required|uuid', 'confirmed' => 'required|accepted']);
        $result = $shares->share($request->user(), $linkId, $data);
        return response()->json($result, $result['replayed'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $linkId, string $shareId, JournalSharingService $shares)
    {
        return response()->json($shares->read($request->user(), $linkId, $shareId))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $linkId, string $shareId, JournalSharingService $shares)
    {
        abort_unless($request->user() instanceof User, 403);
        $shares->revoke($request->user(), $linkId, $shareId);
        return response()->json(['message' => 'Shared copy withdrawn.'])->header('Cache-Control', 'private, no-store');
    }
}
