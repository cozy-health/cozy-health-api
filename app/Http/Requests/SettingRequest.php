<?php
namespace App\Http\Requests;
class SettingRequest extends ApiFormRequest
{
    public function rules(): array { return ['value' => ['present']]; }
}
