<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** Existing models use current-user global scopes. Caller must authorize first. */
class CustomerContext
{
    public function run(User $customer, callable $operation): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        // In-memory scope only: no login, session write or credential issuance.
        $guard->setUser($customer);
        try {
            return $operation();
        } finally {
            if ($previous) {
                $guard->setUser($previous);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
