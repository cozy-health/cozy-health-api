<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SafetyPlan extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'safety_plans';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'warning_signs',
  3 => 'coping_strategies',
  4 => 'distractions',
  5 => 'people',
  6 => 'professionals',
  7 => 'environment_steps',
  8 => 'is_complete',
  9 => 'is_deleted',
  10 => 'last_updated_at',
  11 => 'client_updated_at',
);
    protected $casts = array (
  'warning_signs' => 'array',
  'coping_strategies' => 'array',
  'distractions' => 'array',
  'people' => 'array',
  'professionals' => 'array',
  'environment_steps' => 'array',
  'is_complete' => 'boolean',
  'is_deleted' => 'boolean',
  'last_updated_at' => 'datetime',
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
