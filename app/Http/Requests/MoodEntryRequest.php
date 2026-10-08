<?php
namespace App\Http\Requests;

class MoodEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'], 'mood' => ['required', 'string', 'max:20'],
            'intensity' => ['required', 'integer', 'min:0', 'max:10'],
            'body_sensations' => ['sometimes', 'nullable', 'array'], 'body_sensations.*' => ['string', 'max:255'],
            'triggers' => ['sometimes', 'nullable', 'array'], 'triggers.*' => ['string', 'max:255'],
            'custom_trigger' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sleep_quality' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10'],
            'energy_level' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10'],
            'note' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'coping_strategies' => ['sometimes', 'nullable', 'array'], 'coping_strategies.*' => ['string', 'max:255'],
            'coping_helped' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_crisis_flagged' => ['sometimes', 'boolean'], 'is_deleted' => ['sometimes', 'boolean'],
            'client_created_at' => ['sometimes', 'nullable', 'date'], 'client_updated_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
