<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'enabled', 'description'];
    protected $casts = ['enabled' => 'boolean'];
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('app:config'));
        static::deleted(fn () => Cache::forget('app:config'));
    }
}
