<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'quiz_attempts';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'quiz_slug',
  3 => 'quiz_title',
  4 => 'answers',
  5 => 'score',
  6 => 'interpretation',
  7 => 'is_crisis_flagged',
  8 => 'completed_at',
  9 => 'is_deleted',
  10 => 'client_created_at',
);
    protected $casts = array (
  'answers' => 'array',
  'is_crisis_flagged' => 'boolean',
  'is_deleted' => 'boolean',
  'completed_at' => 'datetime',
  'client_created_at' => 'datetime',
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
