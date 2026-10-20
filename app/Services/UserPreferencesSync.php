<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserPreferencesSync
{
    public function apply(User $user, string $action, array $data): void
    {
        if ($action === 'delete') {
            UserProfile::where('user_id', $user->id)->update(['preferences' => null]);

            return;
        }

        // Phase 6 mobile stores display labels. Accept both labels and wire enums.
        foreach (['focus_areas', 'current_challenges'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = array_map(fn ($value) => is_string($value) ? strtolower($value) : $value, $data[$field]);
            }
        }
        $aliases = [
            'check_in_frequency' => ['Daily' => 'daily', 'A few times a week' => 'few_times', 'When I need it' => 'when_needed'],
            'attribution' => ['Friend' => 'friend', 'App Store' => 'app_store', 'Social' => 'social', 'Search' => 'search', 'Other' => 'other', 'Prefer not to say' => null],
        ];
        foreach ($aliases as $field => $mapping) {
            if (isset($data[$field]) && is_string($data[$field]) && array_key_exists($data[$field], $mapping)) {
                $data[$field] = $mapping[$data[$field]];
            }
        }
        $preferences = Validator::make($data, [
            'focus_areas' => ['present', 'array', 'max:6'],
            'focus_areas.*' => ['string', 'distinct', Rule::in(['anxiety', 'low mood', 'stress', 'sleep', 'relationships', 'self-growth'])],
            'current_challenges' => ['present', 'array', 'max:6'],
            'current_challenges.*' => ['string', 'distinct', Rule::in(['work', 'school', 'family', 'health', 'money', 'loneliness'])],
            'check_in_frequency' => ['required', Rule::in(['daily', 'few_times', 'when_needed'])],
            'attribution' => ['present', 'nullable', Rule::in(['friend', 'app_store', 'social', 'search', 'other'])],
            'completed_at' => ['required', 'date'],
            'skipped' => ['required', 'boolean'],
        ])->validate();
        $preferences['skipped'] = (bool) $preferences['skipped'];

        $profile = UserProfile::firstOrNew(['user_id' => $user->id]);
        if (! $profile->exists) {
            $profile->fill(['id' => (string) Str::uuid(), 'name' => $user->name, 'email' => $user->email]);
        }
        $profile->fill(['preferences' => $preferences, 'onboarding_completed_at' => $preferences['completed_at']])->save();
    }
}
