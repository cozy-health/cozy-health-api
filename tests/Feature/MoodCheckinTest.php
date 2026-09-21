<?php
use App\Models\{User,Feeling,FeelingExpression}; use Illuminate\Foundation\Testing\RefreshDatabase; use Laravel\Sanctum\Sanctum; uses(RefreshDatabase::class);
it('stores a mood checkin',function(){Sanctum::actingAs($u=User::factory()->create());$f=Feeling::create(['name'=>'Happy','slug'=>'happy']);$e=FeelingExpression::create(['feeling_id'=>$f->id,'name'=>'Joyful','slug'=>'joyful']);$this->postJson('/api/v1/mood-checkins',['feeling_exp_id'=>$e->id,'intensity'=>7,'reason_ids'=>[],'coping_mechanism_ids'=>[]])->assertCreated();});
