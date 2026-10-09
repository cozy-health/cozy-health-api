<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'json'];
    public const DEFAULTS = [
        'min_app_version' => '1.0.0', 'latest_app_version' => '1.0.0',
        'update_ios_url' => null, 'update_android_url' => null,
        'maintenance_mode' => false, 'maintenance_message' => null,
        'consent.enforce_blocking' => false,
        'consent.documents' => null, 'consent.provider_documents' => null,
    ];
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('app:config'));
        static::deleted(fn () => Cache::forget('app:config'));
    }
}
