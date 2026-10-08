<?php
namespace App\Http\Requests;
class SyncBatchRequest extends ApiFormRequest { public function rules(): array { return ['operations' => ['sometimes', 'array']]; } }
