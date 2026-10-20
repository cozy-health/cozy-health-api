<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:190', 'unique:users,email,'.auth()->id()],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'date_of_birth' => ['nullable', 'date', 'before:'.$this->user()->localToday()->toDateString()],
            'gender' => ['nullable', 'string', 'max:40'],
            'personalization' => ['nullable', 'array'],
            'privacy_settings' => ['nullable', 'array'],
        ];
    }
}
