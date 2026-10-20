<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderAccessLog extends Model
{
    protected $fillable = ['provider_id', 'user_id', 'link_id', 'action', 'resource_type', 'resource_id', 'ip_address', 'user_agent', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function link() { return $this->belongsTo(ProviderClientLink::class); }
    public const UPDATED_AT = null;
}
