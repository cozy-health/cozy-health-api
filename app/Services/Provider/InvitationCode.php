<?php

namespace App\Services\Provider;

class InvitationCode
{
    public static function lookup(string $email, string $code): string
    {
        return hash_hmac('sha256', strtolower(trim($email)).'|'.$code, config('app.key'));
    }
}
