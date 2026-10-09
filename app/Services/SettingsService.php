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
            'maintenance_mode', 'consent.enforce_blocking' => ['required', 'boolean'],
            'consent.documents', 'consent.provider_documents' => ['required', 'array'],
            'maintenance_message' => ['nullable', 'string', 'max:1000'],
            'min_app_version', 'latest_app_version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            default => ['nullable', 'url:https', 'max:2048'],
        };
        Validator::make(['value' => $value], ['value' => $rule], ['value.regex' => 'Use a version like 1.0.0.'])->validate();
        if (in_array($key, ['maintenance_mode', 'consent.enforce_blocking'])) { $value = filter_var($value, FILTER_VALIDATE_BOOLEAN); }
        if (in_array($key, ['consent.documents', 'consent.provider_documents'])) {
            $defaults = config($key);
            $previous = Setting::find($key)?->value ?? $defaults;
            if (array_diff(array_keys($defaults), array_keys($value)) || array_diff(array_keys($value), array_keys($defaults))) {
                throw ValidationException::withMessages(['value' => ['Include exactly the supported document types.']]);
            }
            Validator::make(['value' => $value], [
                'value.*.title' => ['required', 'string', 'max:150'],
                'value.*.version' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
                'value.*.published' => ['required', 'boolean'], 'value.*.required' => ['required', 'boolean'],
                'value.*.content' => ['present', 'nullable', 'string', 'max:100000'],
            ])->validate();
            foreach ($value as $type => &$document) {
                $document['published'] = filter_var($document['published'], FILTER_VALIDATE_BOOLEAN);
                $document['required'] = $defaults[$type]['required'];
                if ($document['published'] && (str_starts_with($document['version'], 'draft-') || blank($document['content']))) {
                    throw ValidationException::withMessages(['value' => ['Published documents require real text and a non-draft version.']]);
                }
                if ($previous[$type]['published'] && $previous[$type]['version'] === $document['version']
                    && ($previous[$type]['content'] !== $document['content'] || ! $document['published'])) {
                    throw ValidationException::withMessages(['value' => ['Published text is immutable. Use a new version for changes.']]);
                }
            }
            unset($document);
        }
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
