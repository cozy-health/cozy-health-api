<?php

namespace App\Http\Requests;

class MoodEntryUpdateRequest extends MoodEntryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['id']);
        foreach (['mood', 'intensity'] as $field) {
            $rules[$field] = ['sometimes', ...$rules[$field]];
        }

        return $rules;
    }
}
