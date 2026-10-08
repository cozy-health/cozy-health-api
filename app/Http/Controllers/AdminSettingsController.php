<?php
namespace App\Http\Controllers;
use App\Http\Requests\SettingRequest;
use App\Services\SettingsService;
class AdminSettingsController extends Controller
{
    public function update(SettingRequest $request, string $key, SettingsService $settings)
    {
        return response()->json(['data' => $settings->set($key, $request->validated('value'))]);
    }
}
