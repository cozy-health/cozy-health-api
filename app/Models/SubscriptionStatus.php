<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionStatus extends Model
{
    protected $table = 'subscription_statuses';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = array (
  0 => 'id',
  1 => 'user_id',
  2 => 'plan',
  3 => 'status',
  4 => 'platform',
  5 => 'product_id',
  6 => 'transaction_id',
  7 => 'original_transaction_id',
  8 => 'started_at',
  9 => 'expires_at',
  10 => 'cancelled_at',
  11 => 'trial_ends_at',
  12 => 'last_checked',
);
    protected $casts = array (
  'started_at' => 'datetime',
  'expires_at' => 'datetime',
  'cancelled_at' => 'datetime',
  'trial_ends_at' => 'datetime',
  'last_checked' => 'datetime',
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
