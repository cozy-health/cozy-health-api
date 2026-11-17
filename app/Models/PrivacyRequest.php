<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['request_id', 'verified_at', 'identity_fingerprint'];

    protected function casts(): array
    {
        return ['changes' => 'encrypted:array', 'outcome' => 'encrypted', 'verified_at' => 'datetime'];
    }
}
