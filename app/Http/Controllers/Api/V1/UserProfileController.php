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

    public function update(Request $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|nullable|string|max:50|unique:user_profiles,username,'.$profile->id,
            'bio' => 'sometimes|nullable|string|max:150',
            'avatar_url' => 'sometimes|nullable|string|max:500',
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
        ]);

        if (array_key_exists('timezone', $validated)) {
            $request->user()->update(['timezone' => $validated['timezone']]);
            unset($validated['timezone']);
        }

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Profile updated.']);
    }

    public function updatePreferences(Request $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validate([
            'theme' => 'sometimes|string|in:light,dark,system',
            'accent_color' => 'sometimes|string|max:20',
            'language' => 'sometimes|string|max:10',
            'reduce_motion' => 'sometimes|boolean',
            'high_contrast' => 'sometimes|boolean',
            'haptics_enabled' => 'sometimes|boolean',
            'text_size' => 'sometimes|numeric|min:0.8|max:1.5',
            'show_stats' => 'sometimes|boolean',
            'show_username' => 'sometimes|boolean',
        ]);

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Preferences updated.']);
    }

    public function updateNotificationPreferences(Request $request)
    {
        $profile = $this->getOrCreateProfile($request);

        $validated = $request->validate([
            'notifications_master' => 'sometimes|boolean',
            'daily_checkin_enabled' => 'sometimes|boolean',
            'daily_checkin_time' => 'sometimes|string|max:10',
            'journal_reminder_enabled' => 'sometimes|boolean',
            'comments_notifications' => 'sometimes|boolean',
            'achievements_notifications' => 'sometimes|boolean',
            'marketing_notifications' => 'sometimes|boolean',
        ]);

        $profile->update($validated);

        return response()->json(['data' => $this->profileData($request, $profile), 'message' => 'Notification preferences updated.']);
    }

    private function profileData(Request $request, UserProfile $profile): array
    {
        return [...$profile->toArray(), 'timezone' => $request->user()->timezone ?? 'UTC'];
    }
}
