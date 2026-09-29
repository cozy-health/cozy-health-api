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
);
    public function user() { return $this->belongsTo(User::class); }

    protected static function booted()
    {
        static::addGlobalScope('user', function ($query) {
            if (auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }
}
