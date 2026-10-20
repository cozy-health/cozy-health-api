<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'chat_messages';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'conversation_id',
  2 => 'user_id',
  3 => 'role',
  4 => 'content',
  5 => 'status',
  6 => 'is_crisis_flagged',
  7 => 'model_used',
  8 => 'tokens_used',
  9 => 'latency_ms',
  10 => 'client_created_at',
);
    protected $casts = array (
  'is_crisis_flagged' => 'boolean',
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
