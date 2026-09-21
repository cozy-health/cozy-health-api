<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Quiz extends Model { use HasFactory; protected $fillable=['title','slug','type','description','estimated_minutes','is_active','sort_order']; protected function casts():array{return ['is_active'=>'boolean'];} public function questions(){return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');} }
