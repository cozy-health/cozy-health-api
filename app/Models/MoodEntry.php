<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoodEntry extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'mood_entries';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'mood',
  3 => 'intensity',
  4 => 'body_sensations',
  5 => 'triggers',
  6 => 'custom_trigger',
  7 => 'sleep_quality',
  8 => 'energy_level',
  9 => 'note',
  10 => 'coping_strategies',
  11 => 'coping_helped',
  12 => 'is_crisis_flagged',
  13 => 'is_deleted',
  14 => 'client_created_at',
  15 => 'client_updated_at',
);
    protected $casts = array (
  'body_sensations' => 'array',
  'triggers' => 'array',
  'coping_strategies' => 'array',
  'is_crisis_flagged' => 'boolean',
  'is_deleted' => 'boolean',
  'client_created_at' => 'datetime',
  'client_updated_at' => 'datetime',
);
    public function user() { return $this->belongsTo(User::class); }

    protected static function booted()
    {
        static::addGlobalScope('user', function ($query) {
            if (auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }
}
