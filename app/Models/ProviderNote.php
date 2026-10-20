<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderNote extends Model
{
    protected $fillable = ['provider_id', 'user_id', 'body'];
    protected function casts(): array { return []; }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
}
