<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuardianAuthorization extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['guardian_name', 'guardian_email', 'email_hash', 'token_hash', 'session_hash'];
    protected function casts(): array
    {
        return ['guardian_name' => 'encrypted', 'guardian_email' => 'encrypted', 'document_versions' => 'array',
            'expires_at' => 'datetime', 'session_expires_at' => 'datetime', 'email_confirmed_at' => 'datetime',
            'authorized_at' => 'datetime', 'ended_at' => 'datetime', 'erase_at' => 'datetime'];
    }
}
