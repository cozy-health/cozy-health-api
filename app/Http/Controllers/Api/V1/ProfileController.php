<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    use ApiResponse;

    public function show()
    {
        $user = request()->user();
        return $this->success(app(\App\Services\DocumentConsentService::class)->patientConsentCurrent($user)
            ? $user : $user->only(['id', 'name', 'email']));
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->fill($request->validated());
            if ($user->isDirty('email')) {
                $user->forceFill(['email_verified_at' => null, 'email_invalid' => false]);
                DB::table('email_verification_codes')->where('user_id', $user->id)->delete();
            }
            $user->save();
            return $user;
        }, 3);
        return $this->success($user->fresh(), 'Profile updated successfully.');
    }
}
