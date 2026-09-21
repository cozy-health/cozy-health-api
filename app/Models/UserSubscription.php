<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class UserSubscription extends Model { use HasFactory; protected $fillable=['user_id','subscription_package_id','status','started_at','expiry_date','cancelled_at']; protected function casts():array{return ['started_at'=>'datetime','expiry_date'=>'date','cancelled_at'=>'datetime'];} public function package(){return $this->belongsTo(SubscriptionPackage::class,'subscription_package_id');} }
