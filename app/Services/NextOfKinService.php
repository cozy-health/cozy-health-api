<?php

namespace App\Services;

use App\Models\NextOfKinContact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NextOfKinService
{
    public function apply(User $user, string $action, array $data): void
    {
        $rules = ['client_updated_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()]];
        if ($action !== 'delete') {
            Validator::make($data, ['name' => ['required', 'string'], 'phone' => ['required', 'string'], 'relationship' => ['nullable', 'string']])->validate();
            $data['name'] = trim($data['name'] ?? '');
            $data['phone'] = preg_replace('/[\s().-]+/', '', $data['phone'] ?? '');
            $data['relationship'] = trim($data['relationship'] ?? '');
            $rules += ['name' => ['required', 'string', 'max:100'], 'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'], 'relationship' => ['nullable', 'string', 'max:100']];
        }
        $valid = Validator::make($data, $rules)->validate();
        DB::transaction(function () use ($user, $action, $valid) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $record = NextOfKinContact::where('user_id', $user->id)->first();
            $time = CarbonImmutable::parse($valid['client_updated_at']);
            if ($record && $time->lte($record->client_updated_at)) {
                return;
            }
            NextOfKinContact::updateOrCreate(['user_id' => $user->id], [
                'client_updated_at' => $time,
                'contact' => $action === 'delete' ? null : array_intersect_key($valid, array_flip(['name', 'phone', 'relationship'])),
            ]);
        });
    }

    public function read(User $user): ?array
    {
        $record = NextOfKinContact::where('user_id', $user->id)->first();

        return $record ? ['id' => 'primary', 'contact' => $record->contact, 'client_updated_at' => $record->client_updated_at->toISOString()] : null;
    }
}
