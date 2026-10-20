<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushReminder extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['scheduled_at' => 'immutable_datetime', 'eligible_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'send_started_at' => 'immutable_datetime'];
}
