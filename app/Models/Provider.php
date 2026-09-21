<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Provider extends Model { use HasFactory; protected $fillable=['name','email','phone','specialty','license_number','bio','is_verified','is_active']; protected function casts():array{return ['is_verified'=>'boolean','is_active'=>'boolean'];} }
