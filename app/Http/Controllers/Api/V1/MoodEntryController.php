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
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $entry = MoodEntry::updateOrCreate(
            ['id' => $data['id']],
            $data
        );
        if ($entry->wasRecentlyCreated) { app(\App\Services\Analytics\AnalyticsService::class)->track('mood_logged'); }
        return $this->success($entry);
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
    public function update(Request $request, $id) { return $this->success([]); }
    public function destroy($id) { return $this->success([]); }
}
