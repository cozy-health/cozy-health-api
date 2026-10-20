<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmergencyEmailContact extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['token_hash', 'unsubscribe_hash', 'unsubscribe_token'];
    protected function casts(): array { return ['email' => 'encrypted', 'unsubscribe_token' => 'encrypted', 'confirmed_at' => 'datetime', 'token_expires_at' => 'datetime']; }
}
