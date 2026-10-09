<?php
namespace App\Http\Controllers;
use App\Http\Requests\AnalyticsMetricsRequest;
use App\Services\Analytics\AdminMetrics;
class AnalyticsMetricsController extends Controller
{
    public function __invoke(AnalyticsMetricsRequest $request)
    {
        // Legacy alias uses the same private release. Anonymous event rows
        // cannot establish five distinct contributing users.
        return app(AdminMetricsController::class)->show($request, 'usage', app(AdminMetrics::class));
    }
}
