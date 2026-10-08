<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderReport extends Model
{
    protected $fillable = ['provider_id', 'user_id', 'type', 'period_start', 'period_end', 'file_path', 'generated_at'];
    protected function casts(): array { return ['period_start' => 'date', 'period_end' => 'date', 'generated_at' => 'datetime']; }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
    public $timestamps = false;
}
