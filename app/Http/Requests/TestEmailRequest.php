<?php
namespace App\Http\Requests;

class TestEmailRequest extends ApiFormRequest
{
    public function rules(): array { return ['email' => ['required', 'email', 'max:190']]; }
}
