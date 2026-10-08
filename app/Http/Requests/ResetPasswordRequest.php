<?php
namespace App\Http\Requests;
class ResetPasswordRequest extends ApiFormRequest { public function rules(): array { return [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]; } }
