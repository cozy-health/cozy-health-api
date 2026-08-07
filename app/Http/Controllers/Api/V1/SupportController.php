<?php
namespace App\Http\Controllers\Api\V1; use App\Http\Controllers\Controller; use App\Http\Requests\SupportReportRequest; use App\Support\ApiResponse;
class SupportController extends Controller {use ApiResponse; public function store(SupportReportRequest $r){$report=$r->user()->supportReports()->create($r->validated());return $this->success($report,'Problem report submitted successfully.',201);} public function index(){return response()->json(['reports'=>request()->user()->supportReports()->latest()->get()]);}}
