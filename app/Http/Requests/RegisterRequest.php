<?php
namespace App\Http\Requests;
use App\Rules\StrongPassword;

class RegisterRequest extends ApiFormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', new StrongPassword],
            'device_id' => ['sometimes', 'string', 'max:120'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            'gender' => ['nullable', 'string', 'max:40'],
            'personalization' => ['nullable', 'array'],
        ];
    }
}
