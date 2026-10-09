<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'user:make-admin {email} {--create : Create a missing owner-approved account} {--name= : Required name for a new account}';
    protected $description = 'Provision an explicitly authorized active admin without displaying credentials';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:190']]);
        if ($validator->fails()) { $this->error('A valid owner-approved email is required.'); return self::FAILURE; }
        return DB::transaction(function () use ($email) {
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
            if ($user && ($user->trashed() || ! $user->is_active || $user->is_demo)) {
                $this->error('Disabled, demo or deleted accounts cannot be promoted.'); return self::FAILURE;
            }
            if (! $user) {
                if (! $this->option('create')) { $this->error('No account exists. Use --create with --name for an authorized new operator.'); return self::FAILURE; }
                $name = trim((string) $this->option('name'));
                if (Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:120']])->fails()) {
                    $this->error('A name of at most 120 characters is required for a new account.'); return self::FAILURE;
                }
                // Unknown bootstrap password: no usable credential is emitted or logged.
                $user = User::create(['email' => $email, 'name' => $name, 'password' => Str::random(64), 'is_active' => true]);
            }
            $user->forceFill(['role' => 'admin'])->save();
            $this->info('Admin account provisioned. Existing passwords were preserved; new accounts need private password setup.');
            return self::SUCCESS;
        }, 3);
    }
}
