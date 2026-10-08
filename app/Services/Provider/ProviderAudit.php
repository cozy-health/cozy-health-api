<?php

namespace App\Services\Provider;

use App\Models\ProviderAccessLog;
use App\Models\ProviderClientLink;
use Illuminate\Http\Request;

class ProviderAudit
{
    public static function record(Request $request, ProviderClientLink $link, string $action, string $resourceType, ?string $resourceId = null): void
    {
        ProviderAccessLog::create([
            'provider_id' => $link->provider_id, 'user_id' => $link->user_id, 'link_id' => $link->id,
            'action' => $action, 'resource_type' => $resourceType, 'resource_id' => $resourceId,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
