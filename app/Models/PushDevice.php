<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash', 'cleanup_hash'];

    protected $casts = ['token' => 'encrypted', 'expires_at' => 'datetime'];
}
