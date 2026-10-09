<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetAdminPassword extends Command
{
    protected $signature = 'user:admin-password {email}';
    protected $description = 'Set an admin password through hidden prompts and revoke existing credentials';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->where('role', 'admin')->where('is_active', true)->where('is_demo', false)->first();
        if (! $user) { $this->error('No active admin account exists for that email.'); return self::FAILURE; }
        $password = $this->secret('New password');
        $confirmation = $this->secret('Confirm password');
        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 255 || $password !== $confirmation) {
            $this->error('Passwords must match and contain 12 to 255 characters.'); return self::FAILURE;
        }
        $updated = DB::transaction(function () use ($user, $password) {
            $current = User::whereKey($user->id)->where('role', 'admin')->where('is_active', true)->where('is_demo', false)->lockForUpdate()->first();
            if (! $current) return false;
            $current->forceFill(['password' => $password])->save();
            $current->tokens()->delete();
            return true;
        }, 3);
        if (! $updated) { $this->error('Admin authorization changed. No password was updated.'); return self::FAILURE; }
        $this->info('Password updated and existing tokens revoked. Deliver credentials out-of-band; do not include them in reports.');
        return self::SUCCESS;
    }
}
