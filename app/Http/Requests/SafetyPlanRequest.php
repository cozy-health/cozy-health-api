<?php

namespace App\Http\Requests;

class SafetyPlanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = [
            // Legacy mobile singletons use sp-<timestamp>; the writer assigns UUIDs.
            'id' => ['sometimes', 'string', 'max:100'],
            'is_complete' => ['sometimes', 'boolean'],
            'is_deleted' => ['sometimes', 'boolean'],
            'last_updated_at' => ['sometimes', 'nullable', 'date'],
            'client_updated_at' => ['sometimes', 'nullable', 'date'],
        ];
        foreach (['warning_signs', 'coping_strategies', 'distractions', 'environment_steps'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'array', 'max:100'];
            $rules[$field.'.*'] = ['string', 'max:1000'];
        }
        foreach (['people', 'professionals'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'array', 'max:100'];
            $rules[$field.'.*'] = ['array', 'max:10'];
            $rules[$field.'.*.*'] = ['string', 'max:1000'];
        }

        return $rules;
    }
}
