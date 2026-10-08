<?php
namespace App\Http\Requests;
class AnalyticsMetricsRequest extends ApiFormRequest
{
    public function rules(): array { return ['range' => ['sometimes', 'in:7d,30d,90d']]; }
}
