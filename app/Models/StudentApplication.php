<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StudentApplication extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['evidence_path', 'email_token_hash', 'email_token_expires_at'];
    protected function casts(): array { return ['institution_email' => 'encrypted', 'evidence_path' => 'encrypted', 'review_note' => 'encrypted', 'email_verified_at' => 'datetime', 'email_token_expires_at' => 'datetime', 'eligible_until' => 'datetime', 'reviewed_at' => 'datetime', 'evidence_delete_at' => 'datetime']; }
}
