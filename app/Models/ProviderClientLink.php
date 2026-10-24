<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderClientLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['invitation_code_secret', 'verification_actor', 'invitation_code_lookup', 'invitation_sent_at', 'provider_acknowledged_at', 'provider_id', 'user_id', 'provider_email', 'provider_name', 'provider_phone', 'verification_code', 'verification_expires_at', 'verified_at', 'consent_moods', 'consent_journals', 'consent_quizzes', 'consent_notes', 'linked_at', 'revoked_at'];

    protected $hidden = ['invitation_code_secret', 'verification_code', 'verification_expires_at', 'invitation_code_lookup', 'legacy_connection_id'];

    protected $attributes = ['consent_moods' => false, 'consent_journals' => false, 'consent_quizzes' => false, 'consent_notes' => false];

    protected function casts(): array
    {
        return ['invitation_code_secret' => 'encrypted', 'invitation_sent_at' => 'datetime', 'provider_acknowledged_at' => 'datetime', 'consent_moods' => 'boolean', 'consent_journals' => 'boolean', 'consent_quizzes' => 'boolean', 'consent_notes' => 'boolean', 'linked_at' => 'datetime', 'revoked_at' => 'datetime', 'verified_at' => 'datetime', 'verification_expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updated(function (self $link) {
            if (($link->isDirty('consent_journals') && ! $link->consent_journals)
                || ($link->isDirty('revoked_at') && $link->revoked_at)
                || $link->isDirty('provider_id') || ($link->isDirty('verified_at') && ! $link->verified_at)) {
                app(\App\Services\Provider\JournalSharingService::class)->purge(
                    JournalShare::where('provider_client_link_id', $link->id));
            }
        });
        static::saving(function (self $link) {
            if ($link->verified_at || $link->revoked_at) {
                $link->invitation_code_secret = null;
            }
        });

        static::creating(function (self $link) {
            $link->linked_at ??= now();
        });
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')->whereNotNull('verified_at');
    }

    public function consentFlags(): array
    {
        return collect(['moods', 'journals', 'quizzes', 'notes'])->mapWithKeys(fn ($type) => [$type => (bool) $this->{'consent_'.$type}])->all();
    }
}
