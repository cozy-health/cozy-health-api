<?php
namespace App\Http\Requests;
class EmailVerificationRequest extends ApiFormRequest
{
    public function rules(): array { return ['code' => ['required', 'string', 'regex:/^\d{6}$/']]; }
}
