<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable { use HasApiTokens, HasFactory, Notifiable; protected $fillable=['name','email','password','avatar_path','date_of_birth','gender','personalization','privacy_settings','is_active']; protected $hidden=['password','remember_token']; protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed','personalization'=>'array','privacy_settings'=>'array','is_active'=>'boolean','date_of_birth'=>'date']; } public function moodCheckins(){return $this->hasMany(MoodCheckin::class);} public function journals(){return $this->hasMany(Journal::class);} public function quizResults(){return $this->hasMany(QuizResult::class);} public function providerConnection(){return $this->hasOne(ProviderConnection::class);} public function subscription(){return $this->hasOne(UserSubscription::class)->latestOfMany();} public function userSubscriptions(){return $this->hasMany(UserSubscription::class);} public function supportReports(){return $this->hasMany(SupportReport::class);} }
