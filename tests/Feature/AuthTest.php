<?php
use Illuminate\Foundation\Testing\RefreshDatabase; uses(RefreshDatabase::class);
it('registers a user',function(){ $this->postJson('/api/v1/auth/register',['name'=>'Test User','email'=>'test@example.com','password'=>'Fixture7!Strong','password_confirmation'=>'Fixture7!Strong'])->assertCreated()->assertJsonPath('success',true)->assertJsonStructure(['data'=>['user','token']]);});
