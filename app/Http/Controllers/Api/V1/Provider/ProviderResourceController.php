<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccessLog;
use App\Models\ProviderClientLink;
use App\Models\ProviderResource;
use App\Services\Provider\ProviderClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderResourceController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->resources()->orderByDesc('id')->paginate(ProviderClientAccess::pagination($request)));
    }

    public function store(Request $request)
    {
        $resource = $request->user()->resources()->create($request->validate($this->rules()));

        return response()->json(['resource' => $resource], 201);
    }

    public function update(Request $request, string $resourceId)
    {
        return DB::transaction(function () use ($request, $resourceId) {
            $resource = $request->user()->resources()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
            $resource->update($request->validate($this->rules(true)));
            $this->auditAssignments($request, $resource, 'update');

            return response()->json(['resource' => $resource]);
        });
    }

    public function destroy(Request $request, string $resourceId)
    {
        return DB::transaction(function () use ($request, $resourceId) {
            $resource = $request->user()->resources()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
            // The existing schema cascades assignment deletion. Audit each affected patient first.
            $this->auditAssignments($request, $resource, 'delete');
            $resource->delete();

            return response()->json(['message' => 'Resource deleted.']);
        });
    }

    private function auditAssignments(Request $request, ProviderResource $resource, string $action): void
    {
        foreach ($resource->assignments()->lockForUpdate()->get() as $assignment) {
            $linkId = ProviderClientLink::where('provider_id', $resource->provider_id)->where('user_id', $assignment->user_id)->value('id');
            ProviderAccessLog::create(['provider_id' => $resource->provider_id, 'user_id' => $assignment->user_id,
                'link_id' => $linkId, 'action' => $action, 'resource_type' => 'assignment', 'resource_id' => (string) $assignment->id,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        }
    }

    private function rules(bool $patch = false): array
    {
        return ['title' => [$patch ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'type' => [$patch ? 'sometimes' : 'required', 'required', 'string', 'max:100'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'url' => ['sometimes', 'nullable', 'url:http,https', 'max:255']];
    }
}
