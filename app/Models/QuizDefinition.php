<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizDefinition extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['definition' => 'array', 'approved_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->isDirty(['definition', 'fingerprint', 'quiz_id', 'approved_at'])) {
                throw new \LogicException('Published assessment definitions are immutable. Publish a new version.');
            }
        });
    }
}
