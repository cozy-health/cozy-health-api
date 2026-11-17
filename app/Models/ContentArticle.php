<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentArticle extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['draft_payload'];

    protected $casts = ['approved_at' => 'datetime', 'published_at' => 'datetime', 'read_time_minutes' => 'integer', 'draft_payload' => 'array'];

    public function scopePublished($query)
    {
        return $query->whereNotNull('approved_at')->whereNotNull('license')
            ->where('license', '!=', '')->where('published_at', '<=', now());
    }
}
