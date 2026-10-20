<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderNote;
use App\Services\Provider\ProviderAudit;
use App\Services\Provider\ProviderClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderNotesController extends Controller
{
    public function index(Request $request, string $userId)
    {
        return DB::transaction(function () use ($request, $userId) {
            $link = ProviderClientAccess::link($request, $userId, 'notes');
            $notes = ProviderNote::where('provider_id', $link->provider_id)->where('user_id', $link->user_id)
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(ProviderClientAccess::pagination($request));
            ProviderAudit::record($request, $link, 'list', 'note');

            return response()->json($notes);
        });
    }

    public function store(Request $request, string $userId)
    {
        return DB::transaction(function () use ($request, $userId) {
            $link = ProviderClientAccess::link($request, $userId, 'notes');
            $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
            $note = ProviderNote::create(['provider_id' => $link->provider_id, 'user_id' => $link->user_id, 'body' => $data['body']]);
            ProviderAudit::record($request, $link, 'create', 'note', (string) $note->id);

            return response()->json(['note' => $note], 201);
        });
    }

    public function destroy(Request $request, string $userId, string $noteId)
    {
        return DB::transaction(function () use ($request, $userId, $noteId) {
            $link = ProviderClientAccess::link($request, $userId, 'notes');
            $note = ProviderNote::where('provider_id', $link->provider_id)->where('user_id', $link->user_id)->whereKey($noteId)->lockForUpdate()->firstOrFail();
            $note->delete();
            ProviderAudit::record($request, $link, 'delete', 'note', $noteId);

            return response()->json(['message' => 'Note deleted.']);
        });
    }
}
