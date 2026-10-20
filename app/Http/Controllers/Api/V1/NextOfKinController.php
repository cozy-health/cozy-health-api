<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\NextOfKinService;
use Illuminate\Http\Request;

class NextOfKinController extends ApiController
{
    public function show(Request $request, NextOfKinService $service)
    {
        return $this->success($service->read($request->user()));
    }

    public function store(Request $request, NextOfKinService $service)
    {
        $service->apply($request->user(), 'upsert', $request->all() + ['client_updated_at' => now()->toISOString()]);

        return $this->show($request, $service);
    }

    public function destroy(Request $request, NextOfKinService $service)
    {
        $service->apply($request->user(), 'delete', $request->all() + ['client_updated_at' => now()->toISOString()]);

        return $this->show($request, $service);
    }
}
