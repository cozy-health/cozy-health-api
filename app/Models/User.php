<?php

namespace App\Models;

use App\Services\ProfileAvatarService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use SoftDeletes;

    protected $fillable = ['name', 'email', 'timezone', 'password', 'avatar_path', 'date_of_birth', 'gender', 'personalization', 'privacy_settings', 'is_active', 'google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at'];

    protected $attributes = ['timezone' => 'UTC', 'is_demo' => false];

    protected $hidden = ['password', 'remember_token', 'google_sub', 'apple_sub', 'google_linked_at', 'apple_linked_at', 'apple_refresh_token', 'apple_token_client_id', 'age_disputed', 'age_declared_at'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'personalization' => 'array', 'privacy_settings' => 'array', 'is_active' => 'boolean', 'is_demo' => 'boolean', 'date_of_birth' => 'date', 'google_linked_at' => 'datetime', 'apple_linked_at' => 'datetime', 'apple_refresh_token' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            foreach ($user->privateDeletionFiles() as $file) {
                ProfileAvatarService::queueDeletion($file['disk'], $file['key']);
            }
            app(\App\Services\Provider\JournalSharingService::class)->purge(JournalShare::where('user_id', $user->id));
            // Audio ownership is polymorphic, so there is no users foreign-key
            // cascade. Persist cleanup work before removing the metadata.
            AudioAttachment::where('owner_type', 'patient')->where('owner_id', $user->id)
                ->eachById(function ($audio) {
                    $audio->delete();
                });
            NextOfKinContact::where('user_id', $user->id)->delete();
            PushDevice::where('user_id', $user->id)->delete();
            PushReminder::where('user_id', $user->id)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'cancelled']);
        });
        static::updating(function (User $user) {
            if ($user->getOriginal('is_demo') && $user->isDirty('email')) {
                throw ValidationException::withMessages(['email' => ['The demo account email cannot be changed.']]);
            }
        });
    }

    /** Private object references shared by deletion and durable restore receipts. */
    public function privateDeletionFiles(): array
    {
        $files = [];
        foreach (AudioAttachment::where('owner_type', 'patient')->where('owner_id', $this->id)->get() as $audio) {
            $files[] = ['disk' => $audio->disk, 'key' => $audio->storage_key];
        }
        foreach (ProviderReport::where('user_id', $this->id)->pluck('file_path') as $path) {
            $files[] = ['disk' => 'local', 'key' => $path];
        }
        foreach (StudentApplication::where('user_id', $this->id)->whereNotNull('evidence_path')->pluck('evidence_path') as $path) {
            if ($path) $files[] = ['disk' => 'local', 'key' => $path];
        }
        $avatar = $this->profile()->first();
        if ($avatar?->avatar_object_key) $files[] = ['disk' => config('avatars.disk'), 'key' => $avatar->avatar_object_key];
        foreach (\Illuminate\Support\Facades\DB::table('community_posts')->where('user_id', $this->id)->whereNotNull('image_object_key')->pluck('image_object_key') as $path) {
            $files[] = ['disk' => config('avatars.disk'), 'key' => $path];
        }
        return $files;
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

    public function providerClientLinks()
    {
        return $this->hasMany(ProviderClientLink::class);
    }

    public function providerAssignments()
    {
        return $this->hasMany(ProviderAssignment::class);
    }

    public function providerNotes()
    {
        return $this->hasMany(ProviderNote::class);
    }

    public function providerReports()
    {
        return $this->hasMany(ProviderReport::class);
    }

    public function providerConnection()
    {
        return $this->hasOne(ProviderClientLink::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereNull('revoked_at')
            ->where(fn ($eligible) => $eligible->whereNull('replacement_of_link_id')->orWhereNotNull('verified_at')));
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
