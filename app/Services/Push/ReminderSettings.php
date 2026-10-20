<?php

namespace App\Services\Push;

use App\Models\UserProfile;

class ReminderSettings
{
    public const CATEGORIES = ['mood', 'streak', 'journal', 'affirmation'];

    public static function defaults(): array
    {
        return [
            'mood_days' => [1, 2, 3, 4, 5, 6, 7],
            'journal_time' => '18:00', 'journal_days' => [7],
            'streak_enabled' => false, 'streak_time' => '20:00', 'streak_days' => [1, 2, 3, 4, 5, 6, 7],
            'affirmation_enabled' => false, 'affirmation_time' => '12:00', 'affirmation_days' => [1, 2, 3, 4, 5, 6, 7],
            'quiet_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '08:00',
        ];
    }

    public static function for(UserProfile $profile): array
    {
        return array_replace(self::defaults(), $profile->reminder_settings ?? []);
    }

    public static function rules(): array
    {
        $rules = ['push_consent' => 'sometimes|boolean', 'reminder_settings' => 'sometimes|array:'.implode(',', array_keys(self::defaults()))];
        foreach (self::defaults() as $key => $value) {
            $rules['reminder_settings.'.$key] = is_array($value) ? 'sometimes|array|min:1|max:7'
                : (is_bool($value) ? 'sometimes|boolean' : 'sometimes|date_format:H:i');
            if (is_array($value)) {
                $rules['reminder_settings.'.$key.'.*'] = 'integer|between:1,7|distinct';
            }
        }
        return $rules;
    }
}
