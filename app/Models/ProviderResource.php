<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderResource extends Model
{
    protected $fillable = ['provider_id', 'title', 'type', 'duration_minutes', 'description', 'url'];
    protected function casts(): array { return ['duration_minutes' => 'integer']; }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function assignments() { return $this->hasMany(ProviderAssignment::class, 'resource_id'); }
}
