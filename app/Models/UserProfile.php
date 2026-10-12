<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'name',
  3 => 'username',
  4 => 'email',
  5 => 'avatar_url',
  6 => 'bio',
  7 => 'theme',
  8 => 'accent_color',
  9 => 'language',
  10 => 'reduce_motion',
  11 => 'high_contrast',
  12 => 'haptics_enabled',
  13 => 'text_size',
  14 => 'show_stats',
  15 => 'show_username',
  16 => 'notifications_master',
  17 => 'daily_checkin_enabled',
  18 => 'daily_checkin_time',
  19 => 'journal_reminder_enabled',
  20 => 'comments_notifications',
  21 => 'achievements_notifications',
  22 => 'marketing_notifications',
  23 => 'onboarding_completed_at',
  24 => 'client_updated_at',
  25 => 'preferences',
  26 => 'push_consent',
  27 => 'reminder_settings',
);
    protected $casts = array (
  'reduce_motion' => 'boolean',
  'high_contrast' => 'boolean',
  'haptics_enabled' => 'boolean',
  'show_stats' => 'boolean',
  'show_username' => 'boolean',
  'notifications_master' => 'boolean',
  'daily_checkin_enabled' => 'boolean',
  'journal_reminder_enabled' => 'boolean',
  'comments_notifications' => 'boolean',
  'achievements_notifications' => 'boolean',
  'marketing_notifications' => 'boolean',
  'onboarding_completed_at' => 'datetime',
  'client_updated_at' => 'datetime',
  'preferences' => 'array',
  'push_consent' => 'boolean',
  'reminder_settings' => 'array',
);
    public function user() { return $this->belongsTo(User::class); }

    protected static function booted()
    {
        static::saving(function (UserProfile $profile) {
            if ($profile->isDirty('reminder_settings') && $profile->reminder_settings !== null) {
                $profile->reminder_settings = array_replace(\App\Services\Push\ReminderSettings::defaults(),
                    $profile->getOriginal('reminder_settings') ?? [], $profile->reminder_settings);
            }
            if (! $profile->isDirty('email')) {
                return;
            }
            $owner = $profile->user()->first();
            if ($owner?->is_demo && $profile->email !== $owner->email) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['The demo account email cannot be changed.'],
                ]);
            }
        });
        static::saved(function (UserProfile $profile) {
            if (! $profile->push_consent || ! $profile->notifications_master) {
                \App\Models\PushReminder::where('user_id', $profile->user_id)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'cancelled']);
                \App\Models\PushDevice::where('user_id', $profile->user_id)->delete();
            }
        });
        static::addGlobalScope('user', function ($query) {
            if (auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }
}
