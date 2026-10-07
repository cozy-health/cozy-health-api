<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderConnection extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'provider_id', 'provider_email', 'provider_name', 'provider_phone', 'verification_code', 'verification_expires_at', 'verified_at'];

    protected $hidden = ['verification_code'];

    protected function casts(): array
    {
        return ['verification_expires_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
