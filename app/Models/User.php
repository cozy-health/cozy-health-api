<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'timezone', 'password', 'avatar_path', 'date_of_birth', 'gender', 'personalization', 'privacy_settings', 'is_active', 'google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at'];

    protected $attributes = ['timezone' => 'UTC'];

    protected $hidden = ['password', 'remember_token', 'google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at', 'apple_refresh_token', 'apple_token_client_id'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'personalization' => 'array', 'privacy_settings' => 'array', 'is_active' => 'boolean', 'date_of_birth' => 'date', 'google_linked_at' => 'datetime', 'apple_linked_at' => 'datetime', 'apple_refresh_token' => 'encrypted'];
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function moodEntries()
    {
        return $this->hasMany(MoodEntry::class);
    }

    public function journals()
    {
        return $this->hasMany(Journal::class);
    }

    public function quizResults()
    {
        return $this->hasMany(QuizResult::class);
    }

    public function providerConnection()
    {
        return $this->hasOne(ProviderConnection::class);
    }

    public function subscription()
    {
        return $this->hasOne(UserSubscription::class)->latestOfMany();
    }

    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function supportReports()
    {
        return $this->hasMany(SupportReport::class);
    }

    public function getTimezoneAttribute($value): string
    {
        return $value ?? 'UTC';
    }

    public function localNow(): Carbon
    {
        return Carbon::now($this->timezone ?? 'UTC');
    }

    public function localToday(): Carbon
    {
        return $this->localNow()->startOfDay();
    }
}
