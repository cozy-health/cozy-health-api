<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppConfigService;

class ConfigController extends Controller
{
    public function show(AppConfigService $config) { return response()->json($config->get()); }
}
