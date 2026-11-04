<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['delivery_id', 'request_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
