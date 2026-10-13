<?php
namespace App\Services;
use App\Models\{User, UserProfile};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
class UsernameService
{
    public function validate(User $user, mixed $username): ?string
    {
        if ($username === null) return null;
        $username = is_string($username) ? trim($username) : $username;
        if ($username === $user->profile?->username) return $username;
        Validator::make(['username' => $username], ['username' => 'required|string|min:3|max:50|regex:/^[A-Za-z0-9_]+$/'])->validate();
        if (UserProfile::withoutGlobalScope('user')->where('username_key', strtolower($username))->where('user_id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['username' => 'This username is already taken.']);
        }
        return $username;
    }
}
