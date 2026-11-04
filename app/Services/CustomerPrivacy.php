<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\UserProfileController;
use App\Http\Requests\UserProfileRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CustomerPrivacy
{
    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->email.'|'.$user->password, config('app.key'));
    }

    public function verify(User $user, string $password): void
    {
        abort_unless($user->is_active && $user->email_verified_at && Hash::check($password, $user->password), 422, 'Confirm your current password and verified email before requesting this action.');
    }

    public function profileRequest(User $user, array $changes): UserProfileRequest
    {
        $request = UserProfileRequest::create('/api/v1/user/profile', 'PATCH', $changes);
        $request->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $user);
        $request->validateResolved();

        return $request;
    }

    public function validateChanges(User $user, array $changes): array
    {
        abort_if(array_diff(array_keys($changes), ['name', 'username', 'bio', 'timezone', 'country_code']) !== [], 422, 'Only editable profile fields can be corrected here.');
        abort_if($changes === [], 422, 'Choose a profile field to correct.');

        return app(CustomerContext::class)->run($user, fn () => $this->profileRequest($user, $changes)->validated());
    }

    public function correct(User $user, array $changes): void
    {
        app(CustomerContext::class)->run($user, fn () => app(UserProfileController::class)->update($this->profileRequest($user, $changes)));
    }
}
