<?php
namespace App\Http\Requests;

class PaginationRequest extends ApiFormRequest
{
    public function rules(): array { return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]; }
}
