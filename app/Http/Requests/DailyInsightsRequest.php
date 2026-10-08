<?php
namespace App\Http\Requests;
class DailyInsightsRequest extends ApiFormRequest { public function rules(): array { return ['days' => ['sometimes', 'integer', 'in:7,30,90']]; } }
