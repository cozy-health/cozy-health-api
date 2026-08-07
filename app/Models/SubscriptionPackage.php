<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class SubscriptionPackage extends Model { use HasFactory; protected $fillable=['package_name','slug','description','price','currency','duration','duration_days','features','is_active','sort_order']; protected function casts():array{return ['features'=>'array','is_active'=>'boolean','price'=>'decimal:2'];} }
