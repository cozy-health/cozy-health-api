<?php
namespace App\Http\Requests;
class WeeklyInsightsRequest extends ApiFormRequest { public function rules(): array { return ['weeks' => ['sometimes', 'integer', 'in:1,4,12']]; } }
