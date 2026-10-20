<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function messages(): array
    {
        return [
            'required' => 'Please provide :attribute.',
            'email' => 'Please enter a valid email address.',
            'uuid' => 'Please provide a valid :attribute identifier.',
            'date' => 'Please enter a valid date for :attribute.',
            'integer' => 'Please enter a whole number for :attribute.',
            'array' => 'Please provide a list for :attribute.',
            'boolean' => 'Please choose true or false for :attribute.',
            'in' => 'Please choose a supported option for :attribute.',
            'max' => 'Please use a shorter or smaller value for :attribute.',
            'string' => 'Please enter text for :attribute.',
        ];
    }
}
