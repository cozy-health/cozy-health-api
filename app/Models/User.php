<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'timezone', 'password', 'avatar_path', 'date_of_birth', 'gender', 'personalization', 'privacy_settings', 'is_active', 'google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at'];

    protected $attributes = ['timezone' => 'UTC', 'is_demo' => false];

    protected $hidden = ['password', 'remember_token', 'google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at', 'apple_refresh_token', 'apple_token_client_id'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'personalization' => 'array', 'privacy_settings' => 'array', 'is_active' => 'boolean', 'is_demo' => 'boolean', 'date_of_birth' => 'date', 'google_linked_at' => 'datetime', 'apple_linked_at' => 'datetime', 'apple_refresh_token' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->getOriginal('is_demo') && $user->isDirty('email')) {
                throw ValidationException::withMessages(['email' => ['The demo account email cannot be changed.']]);
            }
        });
    }

    // Future paywall and 2FA gates should consult these policies.
    public function bypassesPaywall(): bool
    {
        return (bool) $this->is_demo;
    }

    public function bypassesTwoFactorAuthentication(): bool
    {
        return (bool) $this->is_demo;
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

    public function providerClientLinks() { return $this->hasMany(ProviderClientLink::class); }
    public function providerAssignments() { return $this->hasMany(ProviderAssignment::class); }
    public function providerNotes() { return $this->hasMany(ProviderNote::class); }
    public function providerReports() { return $this->hasMany(ProviderReport::class); }

    public function providerConnection()
    {
        return $this->hasOne(ProviderClientLink::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereNull('revoked_at'));
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
