<?php

namespace App\Services\Provider;

use App\Models\ProviderClientLink;
use Illuminate\Http\Request;

class ProviderClientAccess
{
    // Call inside a transaction. Locking serializes patient revocation/consent against reads and writes.
    public static function link(Request $request, string $userId, ?string $consent = null): ProviderClientLink
    {
        if (config('age.enabled')) {
            \App\Models\User::whereKey($userId)->lockForUpdate()->firstOrFail();
        }
        $link = ProviderClientLink::active()->where('provider_id', $request->user()->id)
            ->where('user_id', $userId)->whereHas('user')->lockForUpdate()->firstOrFail();
        abort_if($consent && ! $link->{'consent_'.$consent}, 404);
        abort_unless(app(\App\Services\AgeEligibility::class)->allows($link->user), 403, 'Patient records are unavailable.');

        return $link;
    }

    public static function pagination(Request $request): int
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return (int) $request->input('per_page', 20);
    }
}
