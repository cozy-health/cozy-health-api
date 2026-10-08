<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProviderRequestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_email' => ['required_without:email', 'email', 'max:190'],
            'email' => ['required_without:provider_email', 'email', 'max:190'],
            'provider_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'provider_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
