<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class MoodCheckin extends Model { use HasFactory; protected $fillable=['user_id','feeling_expression_id','intensity','journal','checked_in_at']; protected function casts():array{return ['checked_in_at'=>'datetime'];} public function user(){return $this->belongsTo(User::class);} public function feelingExpression(){return $this->belongsTo(FeelingExpression::class);} public function causes(){return $this->belongsToMany(FeelingCause::class,'mood_checkin_causes');} public function copingMechanisms(){return $this->belongsToMany(CopingMechanism::class,'mood_checkin_coping_mechanisms');} }
