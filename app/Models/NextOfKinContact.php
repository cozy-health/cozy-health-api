<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NextOfKinContact extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['user_id', 'contact', 'client_updated_at'];

    protected function casts(): array
    {
        return ['contact' => 'encrypted:array', 'client_updated_at' => 'immutable_datetime'];
    }
}
