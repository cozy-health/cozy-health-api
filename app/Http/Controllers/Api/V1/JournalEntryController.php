<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class JournalEntryController extends ApiController
{
    public function index(Request $request)
    {
        return $this->success(JournalEntry::orderBy('client_created_at', 'desc')->paginate($request->input('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['id' => ['sometimes', 'uuid', 'unique:journal_entries,id'], ...$this->rules(true)]);
        $data['id'] ??= (string) Str::uuid();
        $data['body'] = $data['body'] ?? '';
        $data['user_id'] = $request->user()->id;
        return $this->success(JournalEntry::create($data)->refresh());
    }

    public function show($id)
    {
        $entry = JournalEntry::find($id);
        return $entry ? $this->success($entry) : $this->notFound();
    }

    public function update(Request $request, $id)
    {
        $entry = JournalEntry::where('user_id', $request->user()->id)->find($id);
        if (!$entry) {
            return $this->notFound();
        }
        $data = $request->validate($this->rules(false));
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

    private function rules(bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        return [
            'type' => [$presence, Rule::in(['free', 'guided', 'voice'])],
            'is_draft' => [$presence, 'boolean'],
            'client_created_at' => [$presence, 'date'],
            'client_updated_at' => ['sometimes', 'nullable', 'date'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'voice_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'voice_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'transcription' => ['sometimes', 'nullable', 'string'],
            'prompt_id' => ['sometimes', 'nullable', 'uuid'],
            'prompt_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string'],
            'linked_mood_entry_id' => ['sometimes', 'nullable', 'uuid'],
            'word_count' => ['sometimes', 'integer', 'min:0'],
            'is_crisis_flagged' => ['sometimes', 'boolean'],
        ];
    }

    private function notFound()
    {
        return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found.']], 404);
    }
}
