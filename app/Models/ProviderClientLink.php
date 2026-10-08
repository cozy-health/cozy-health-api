<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderClientLink extends Model
{
    public $timestamps = false;
    protected $fillable = ['provider_id', 'user_id', 'consent_moods', 'consent_journals', 'consent_quizzes', 'consent_notes', 'linked_at', 'revoked_at'];
    protected function casts(): array { return ['consent_moods' => 'boolean', 'consent_journals' => 'boolean', 'consent_quizzes' => 'boolean', 'consent_notes' => 'boolean', 'linked_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function scopeActive($query) { return $query->whereNull('revoked_at'); }
}
