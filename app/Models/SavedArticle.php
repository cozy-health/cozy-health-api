<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedArticle extends Model
{
    protected $table = 'saved_articles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'article_id',
  3 => 'article_slug',
  4 => 'title',
  5 => 'category',
  6 => 'read_time_minutes',
  7 => 'saved_at',
  8 => 'client_created_at',
);
    protected $casts = array (
  'saved_at' => 'datetime',
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
