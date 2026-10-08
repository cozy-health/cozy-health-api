<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class JournalEntryController extends ApiController
{
    public function index(\App\Http\Requests\PaginationRequest $request)
    {
        return $this->success(JournalEntry::orderBy('client_created_at', 'desc')->paginate($request->input('per_page', 20)));
    }

    public function store(\App\Http\Requests\JournalEntryRequest $request)
    {
        $data = $request->validated();
        $data['id'] ??= (string) Str::uuid();
        $data['body'] = $data['body'] ?? '';
        $data['user_id'] = $request->user()->id;
        $entry = JournalEntry::create($data)->refresh();
        app(\App\Services\Analytics\AnalyticsService::class)->track('journal_created');
        return $this->success($entry);
    }

    public function show($id)
    {
        $entry = JournalEntry::find($id);
        return $entry ? $this->success($entry) : $this->notFound();
    }

    public function update(\App\Http\Requests\JournalEntryRequest $request, $id)
    {
        $entry = JournalEntry::where('user_id', $request->user()->id)->find($id);
        if (!$entry) {
            return $this->notFound();
        }
        $data = $request->validated();
        if (array_key_exists('body', $data)) {
            $data['body'] ??= '';
        }
        $entry->update($data);
        return $this->success($entry->refresh());
    }

    public function destroy(Request $request, $id)
    {
        $entry = JournalEntry::where('user_id', $request->user()->id)->find($id);
        if (!$entry) {
            return $this->notFound();
        }
        $entry->delete();
        return response()->json(['message' => 'Journal entry deleted.']);
    }

    private function notFound()
    {
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found.']], 404);
    }
}
