<?php
namespace App\Http\Requests;
class BrevoWebhookRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['event' => ['required', 'string', 'max:40'], 'email' => ['required', 'email', 'max:190'],
            'message-id' => ['required', 'string', 'max:255'], 'date' => ['required', 'date']];
    }
}
