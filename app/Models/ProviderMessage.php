<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'records' => 'array', 'read_at' => 'datetime'];
    }
}
