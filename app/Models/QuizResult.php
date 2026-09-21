<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class QuizResult extends Model { use HasFactory; protected $fillable=['user_id','quiz_id','score','max_score','percentage','interpretation','answers','completed_at']; protected function casts():array{return ['answers'=>'array','completed_at'=>'datetime','percentage'=>'decimal:2'];} public function quiz(){return $this->belongsTo(Quiz::class);} public function user(){return $this->belongsTo(User::class);} }
