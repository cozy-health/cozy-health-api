<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrisisResource extends Model
{
    protected $guarded = [];

    protected $casts = ['languages' => 'array', 'is_active' => 'boolean', 'verified_at' => 'datetime', 'reviewed_at' => 'datetime'];
}
