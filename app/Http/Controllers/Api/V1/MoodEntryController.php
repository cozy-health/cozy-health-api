<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use App\Models\MoodEntry;

class MoodEntryController extends ApiController {
    public function index(\App\Http\Requests\PaginationRequest $request) {
        $entries = MoodEntry::orderBy('client_created_at', 'desc')
            ->paginate($request->input('per_page', 20));
        return $this->success($entries);
    }
    public function store(\App\Http\Requests\MoodEntryRequest $request) {
        return $this->persist($request, $request->validated());
    }
    public function show($id) {
        $entry = MoodEntry::find($id);
        if (!$entry) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Mood entry not found.'
                ]
            ], 404);
        }
        return $this->success($entry);
    }
    public function update(\App\Http\Requests\MoodEntryUpdateRequest $request, string $id) {
        MoodEntry::where('user_id', $request->user()->id)->findOrFail($id);
        return $this->persist($request, [...$request->validated(), 'id' => $id]);
    }
    public function destroy(Request $request, string $id) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id) {
            MoodEntry::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            app(\App\Services\SyncRecordWriter::class)->delete(MoodEntry::class, 'mood_entry', $request->user(), $id);
        });
        return $this->success(['deleted' => true]);
    }
    private function persist(Request $request, array $data) {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $data['user_id'] = $request->user()->id;
            $error = app(\App\Services\SyncRecordWriter::class)->upsert(MoodEntry::class, 'mood_entry', $request->user(), $data);
            if ($error !== null) return $this->error('This record cannot be saved with that identifier.', 'CONFLICT', [], 409);
            // A stale retry may acknowledge a tombstone, never resurrect it.
            return $this->success(MoodEntry::withTrashed()->where('user_id', $request->user()->id)->findOrFail($data['id']));
        });
    }
}
