<?php
namespace App\Http\Requests;
use App\Models\User;
use App\Rules\{ResidenceCountry, StrongPassword};
use App\Services\AgeEligibility;

class RegisterRequest extends ApiFormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $enforced = config('age.enabled');
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', new StrongPassword],
            'date_of_birth' => ['bail', $enforced ? 'required' : 'nullable', 'date_format:Y-m-d', 'before:today',
                function ($attribute, $value, $fail) {
                    if ($value && app(AgeEligibility::class)->age(new User(['date_of_birth' => $value])) < 13) {
                        $fail('Cozy Health accounts are for ages 13 and older.');
                    }
                }],
            'residence_country' => [$enforced ? 'required' : 'nullable', new ResidenceCountry],
            'device_id' => ['sometimes', 'string', 'max:120'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            'gender' => [$enforced ? 'prohibited' : 'nullable', 'string', 'max:40'],
            'personalization' => [$enforced ? 'prohibited' : 'nullable', 'array'],
        ];
    }
}
