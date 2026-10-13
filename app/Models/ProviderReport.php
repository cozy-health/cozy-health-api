<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderReport extends Model
{
    protected $fillable = ['provider_id', 'user_id', 'type', 'period_start', 'period_end', 'file_path', 'generated_at', 'consent_snapshot', 'link_id', 'link_verified_at', 'quiz_share_snapshot'];

    protected $hidden = ['file_path', 'consent_snapshot', 'link_id', 'link_verified_at', 'quiz_share_snapshot'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'generated_at' => 'datetime', 'consent_snapshot' => 'array', 'link_id' => 'integer', 'link_verified_at' => 'datetime', 'quiz_share_snapshot' => 'array'];
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public $timestamps = false;
}
