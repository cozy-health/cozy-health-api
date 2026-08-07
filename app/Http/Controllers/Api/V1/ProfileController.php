<?php
namespace App\Http\Controllers\Api\V1; use App\Http\Controllers\Controller; use App\Http\Requests\UpdateProfileRequest; use App\Support\ApiResponse;
class ProfileController extends Controller {use ApiResponse; public function show(){return $this->success(request()->user());} public function update(UpdateProfileRequest $r){$u=$r->user();$u->update($r->validated());return $this->success($u->fresh(),'Profile updated successfully.');}}
