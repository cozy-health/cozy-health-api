<?php
namespace App\Http\Requests;
class ForgotPasswordRequest extends ApiFormRequest { public function rules(): array { return [
            'email' => 'required|email',
        ]; } }
