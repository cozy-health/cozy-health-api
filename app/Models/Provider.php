<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Provider extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'specialty', 'license_number', 'license_state', 'bio', 'practice', 'password_hash', 'timezone', 'is_verified', 'is_active'];
    protected $hidden = ['password_hash', 'remember_token', 'email_invalid', 'email_unsubscribed_at'];
    protected $attributes = ['timezone' => 'UTC', 'is_verified' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['password_hash' => 'hashed', 'is_verified' => 'boolean', 'is_active' => 'boolean', 'email_invalid' => 'boolean', 'email_unsubscribed_at' => 'datetime'];
    }

    public function getAuthPasswordName(): string { return 'password_hash'; }
    public function getAuthPassword(): string { return $this->password_hash ?? ''; }
    public function clientLinks() { return $this->hasMany(ProviderClientLink::class); }
    public function notes() { return $this->hasMany(ProviderNote::class); }
    public function resources() { return $this->hasMany(ProviderResource::class); }
    public function assignments() { return $this->hasMany(ProviderAssignment::class); }
    public function reports() { return $this->hasMany(ProviderReport::class); }
    public function accessLogs() { return $this->hasMany(ProviderAccessLog::class); }
}
