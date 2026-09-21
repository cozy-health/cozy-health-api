<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Feeling extends Model { use HasFactory; protected $fillable=['name','slug','description','sort_order','is_active']; protected function casts():array{return ['is_active'=>'boolean'];} public function expressions(){return $this->hasMany(FeelingExpression::class);} }
