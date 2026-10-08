<?php
namespace App\Http\Requests;
class UserProfileRequest extends ApiFormRequest { public function rules(): array { return [
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|nullable|string|max:50|unique:user_profiles,username,'.$this->user()->profile()->value('id'),
            'bio' => 'sometimes|nullable|string|max:150',
            'avatar_url' => 'sometimes|nullable|string|max:500',
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
        ]; } }
