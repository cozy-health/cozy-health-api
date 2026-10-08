<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        return response()->json(['data' => $this->profileData($request, $this->getOrCreateProfile($request))]);
    }

    public function update(\App\Http\Requests\UserProfileRequest $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validated();

        if (array_key_exists('timezone', $validated)) {
            $request->user()->update(['timezone' => $validated['timezone']]);
            unset($validated['timezone']);
        }

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Profile updated.']);
    }

    public function updatePreferences(\App\Http\Requests\UserDisplayPreferencesRequest $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validated();

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Preferences updated.']);
    }

    public function updateNotificationPreferences(\App\Http\Requests\NotificationPreferencesRequest $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validated();

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Notification preferences updated.']);
    }

    private function profileData(Request $request, UserProfile $profile): array
    {
        return [...$profile->toArray(), 'timezone' => $request->user()->timezone ?? 'UTC'];
    }
}
