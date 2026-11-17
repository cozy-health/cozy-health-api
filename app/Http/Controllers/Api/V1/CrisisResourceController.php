<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CrisisResource;
use Illuminate\Http\Request;

class CrisisResourceController extends ApiController
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['country_code' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/']]);
        $country = $data['country_code'] ?? $request->user()?->country_code;
        $resources = CrisisResource::where('is_active', true)->whereNotNull('verified_at')->whereNotNull('reviewed_at')
            ->where('verified_at', '>=', now()->subMonths(6))
            ->whereIn('country_code', array_unique(array_filter([$country, 'ZZ'])))
            ->orderBy('name')->get();

        return $this->success($resources, ['country_code' => $country,
            'region_required' => $country === null, 'resources_available' => $resources->isNotEmpty()]);
    }
}
