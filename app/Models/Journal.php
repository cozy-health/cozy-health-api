<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Journal extends Model { use HasFactory; protected $fillable=['user_id','title','content','mood','entry_date']; protected function casts():array{return ['entry_date'=>'date'];} public function user(){return $this->belongsTo(User::class);} }
