<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class SupportReport extends Model { use HasFactory; protected $fillable=['user_id','subject','message','status','admin_notes','request_id','support_revision','resolved_at']; protected $hidden=['admin_notes','request_id']; public function user(){return $this->belongsTo(User::class);} }
