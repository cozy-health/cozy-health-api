<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $common = require __DIR__.'/../../resources/security/common_passwords.php';
        if (! is_string($value) || strlen($value) < 8 || strlen($value) > 255 ||
            ! preg_match('/[a-z]/i', $value) || ! preg_match('/[0-9]/', $value) || in_array(strtolower($value), $common, true)) {
            $fail('Use 8–255 characters including a letter and number, and avoid common passwords.');
        }
    }
}
