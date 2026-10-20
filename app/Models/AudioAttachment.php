<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioAttachment extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['owner_type', 'owner_id', 'disk', 'storage_key', 'client_id'];

    public function ownedBy($actor): bool
    {
        return $this->owner_type === ($actor instanceof Provider ? 'provider' : 'patient')
            && (int) $this->owner_id === (int) $actor->id;
    }
}
