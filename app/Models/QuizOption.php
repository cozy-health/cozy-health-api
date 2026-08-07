<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class QuizOption extends Model { use HasFactory; protected $fillable=['quiz_question_id','label','value','score','sort_order']; public function question(){return $this->belongsTo(QuizQuestion::class,'quiz_question_id');} }
