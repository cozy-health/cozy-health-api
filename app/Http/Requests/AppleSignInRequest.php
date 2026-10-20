<?php
namespace App\Http\Requests;
class AppleSignInRequest extends ApiFormRequest { public function rules(): array { return [
            'identity_token' => ['required', 'string', 'max:16384'],
            'authorization_code' => ['sometimes', 'string', 'max:4096'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]; } }
