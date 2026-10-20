<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderAssignment extends Model
{
    protected $fillable = ['provider_id', 'user_id', 'resource_id', 'status', 'assigned_at', 'completed_at', 'notes'];
    protected function casts(): array { return ['assigned_at' => 'datetime', 'completed_at' => 'datetime']; }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
    public $timestamps = false;
    public function resource() { return $this->belongsTo(ProviderResource::class, 'resource_id'); }
}
