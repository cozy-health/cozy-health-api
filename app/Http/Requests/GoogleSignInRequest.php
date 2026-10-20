<?php
namespace App\Http\Requests;
class GoogleSignInRequest extends ApiFormRequest { public function rules(): array { return [
            'id_token' => ['required', 'string', 'max:16384'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]; } }
