<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Affirmation extends Model
{
    protected $fillable = ['body', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
