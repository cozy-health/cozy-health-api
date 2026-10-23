<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationPreferencesRequest;
use App\Http\Requests\UserDisplayPreferencesRequest;
use App\Http\Requests\UserProfileRequest;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserProfileController extends Controller
{
    private function getOrCreateProfile(Request $request): UserProfile
    {
        $user = $request->user();
        $profile = $user->profile()->first();

        if (! $profile) {
            $profile = $user->profile()->create([
                'id' => (string) Str::uuid(),
                'name' => $user->name,
                'email' => $user->email,
                'theme' => 'system',
                'accent_color' => '#0460D8',
                'language' => 'en',
                'reduce_motion' => false,
                'high_contrast' => false,
                'haptics_enabled' => true,
                'text_size' => 1.00,
                'show_stats' => false,
                'show_username' => true,
                'notifications_master' => true,
                'daily_checkin_enabled' => true,
                'daily_checkin_time' => '09:00',
                'journal_reminder_enabled' => false,
                'comments_notifications' => true,
                'achievements_notifications' => true,
                'marketing_notifications' => false,
            ]);
        }

        return $profile;
    }

    public function show(Request $request)
    {
        if (! app(\App\Services\AgeEligibility::class)->allows($request->user())
            || ! app(\App\Services\DocumentConsentService::class)->patientConsentCurrent($request->user())) {
            $user = $request->user();
            return response()->json(['data' => ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email]])->header('Cache-Control', 'private, no-store');
        }
        return response()->json(['data' => $this->profileData($request, $this->getOrCreateProfile($request))]);
    }

    public function update(UserProfileRequest $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validated();

        if (array_key_exists('username', $validated)) {
            $validated['username'] = app(\App\Services\UsernameService::class)->validate($request->user(), $validated['username']);
        }

        if (array_key_exists('timezone', $validated)) {
            $request->user()->update(['timezone' => $validated['timezone']]);
            unset($validated['timezone']);
        }
        if (array_key_exists('country_code', $validated)) {
            $request->user()->forceFill(['country_code' => $validated['country_code']])->save();
            unset($validated['country_code']);
        }

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Profile updated.']);
    }

    public function updatePreferences(UserDisplayPreferencesRequest $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validated();

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Preferences updated.']);
    }

    public function updateNotificationPreferences(NotificationPreferencesRequest $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validated();

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Notification preferences updated.']);
    }

    public function notificationPreferences(Request $request)
    {
        return response()->json(['data' => $this->getOrCreateProfile($request)->only([
            'notifications_master', 'daily_checkin_enabled', 'daily_checkin_time',
            'journal_reminder_enabled', 'comments_notifications', 'achievements_notifications', 'marketing_notifications',
            'push_consent', 'reminder_settings',
        ])]);
    }

    private function profileData(Request $request, UserProfile $profile): array
    {
        return [...$profile->toArray(), 'timezone' => $request->user()->timezone ?? 'UTC', 'country_code' => $request->user()->country_code,
            'account_created_at' => $request->user()->created_at?->toIso8601String()];
    }
}
