<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class FeelingExpression extends Model { use HasFactory; protected $fillable=['feeling_id','name','slug','emoji','description','sort_order','is_active']; protected function casts():array{return ['is_active'=>'boolean'];} public function feeling(){return $this->belongsTo(Feeling::class);} }
