<?php
namespace App\Http\Requests;
use Illuminate\Validation\Rule;
class JournalEntryRequest extends ApiFormRequest {
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        return [
            'id' => [$this->isMethod('POST') ? 'sometimes' : 'prohibited', 'uuid', 'unique:journal_entries,id'],
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

}
