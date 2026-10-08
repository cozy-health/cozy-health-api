<?php
namespace App\Services;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    public function set(string $key, mixed $value): Setting
    {
        if (! array_key_exists($key, Setting::DEFAULTS)) {
            throw ValidationException::withMessages(['key' => ['Choose a supported setting.']]);
        }
        $rule = match ($key) {
            'maintenance_mode' => ['required', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:1000'],
            'min_app_version', 'latest_app_version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            default => ['nullable', 'url:https', 'max:2048'],
        };
        Validator::make(['value' => $value], ['value' => $rule], ['value.regex' => 'Use a version like 1.0.0.'])->validate();
        if ($key === 'maintenance_mode') { $value = filter_var($value, FILTER_VALIDATE_BOOLEAN); }
        if (str_starts_with($key, 'update_') && $value !== null) {
            $host = strtolower(parse_url($value, PHP_URL_HOST) ?? '');
            $allowed = $key === 'update_ios_url' ? ['apps.apple.com'] : ['play.google.com'];
            if (! in_array($host, $allowed, true)) {
                throw ValidationException::withMessages(['value' => ['Use the official App Store or Google Play URL.']]);
            }
        }
        return Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
