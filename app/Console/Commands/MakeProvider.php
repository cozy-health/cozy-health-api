<?php

namespace App\Console\Commands;

use App\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MakeProvider extends Command
{
    protected $signature = 'user:make-provider {email} {name} {practice} {license_number} {license_state}';
    protected $description = 'Create a verified provider with a temporary password';

    public function handle(): int
    {
        $data = [
            'email' => strtolower(trim($this->argument('email'))),
            'name' => $this->argument('name'),
            'practice' => $this->argument('practice'),
            'license_number' => $this->argument('license_number'),
            'license_state' => strtoupper(trim($this->argument('license_state'))),
        ];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:190', 'unique:providers,email'],
            'name' => ['required', 'string', 'max:120'],
            'practice' => ['required', 'string', 'max:255'],
            'license_number' => ['required', 'string', 'max:255', Rule::unique('providers')->where('license_state', $data['license_state'])],
            'license_state' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) { $this->error($error); }
            return self::FAILURE;
        }
        $password = Str::password(24);
        Provider::create([...$data, 'password_hash' => $password, 'is_verified' => true, 'is_active' => true]);
        $this->info('Provider created. Temporary password: '.$password);
        $this->info('Send this password out-of-band. Ask the provider to reset it before use.');
        return self::SUCCESS;
    }
}
