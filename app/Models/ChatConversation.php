<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    protected $table = 'chat_conversations';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'title',
  3 => 'last_message_preview',
  4 => 'message_count',
  5 => 'is_archived',
  6 => 'client_updated_at',
);
    protected $casts = array (
  'is_archived' => 'boolean',
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
