<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderAssignment;
use App\Models\ProviderClientLink;
use App\Models\ProviderResource;
use App\Services\Provider\ProviderAudit;
use App\Services\Provider\ProviderClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderAssignmentController extends Controller
{
    public function index(Request $request, string $userId)
    {
        return DB::transaction(function () use ($request, $userId) {
            $link = ProviderClientAccess::link($request, $userId);
            $assignments = ProviderAssignment::where('provider_id', $link->provider_id)->where('user_id', $userId)
                ->with('resource')->orderByDesc('id')->paginate(ProviderClientAccess::pagination($request));
            ProviderAudit::record($request, $link, 'list', 'assignment');

            return $assignments;
        });
    }

    public function store(Request $request, string $userId)
    {
        $data = $request->validate(['resource_id' => ['required', 'integer'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']]);

        return DB::transaction(function () use ($request, $userId, $data) {
            $link = ProviderClientAccess::link($request, $userId);
            $resource = ProviderResource::where('provider_id', $link->provider_id)->lockForUpdate()->findOrFail($data['resource_id']);
            $assignment = ProviderAssignment::create(['provider_id' => $link->provider_id, 'user_id' => $userId,
                'resource_id' => $resource->id, 'notes' => $data['notes'] ?? null, 'status' => 'assigned', 'assigned_at' => now()]);
            ProviderAudit::record($request, $link, 'create', 'assignment', (string) $assignment->id);

            return response()->json(['assignment' => $assignment->load('resource')], 201);
        });
    }

    public function destroy(Request $request, string $userId, string $assignmentId)
    {
        return DB::transaction(function () use ($request, $userId, $assignmentId) {
            $link = ProviderClientAccess::link($request, $userId);
            $assignment = ProviderAssignment::where('provider_id', $link->provider_id)->where('user_id', $userId)->lockForUpdate()->findOrFail($assignmentId);
            ProviderAudit::record($request, $link, 'delete', 'assignment', (string) $assignment->id);
            $assignment->delete();

            return response()->json(['message' => 'Resource assignment removed.']);
        });
    }

    public function patientIndex(Request $request)
    {
        return $this->patientAssignments($request)->with(['resource', 'provider:id,name,practice'])
            ->orderByDesc('id')->paginate(ProviderClientAccess::pagination($request));
    }

    public function patientUpdate(Request $request, string $assignmentId)
    {
        $data = $request->validate(['status' => ['required', 'in:completed,declined']]);

        return DB::transaction(function () use ($request, $assignmentId, $data) {
            $assignment = $this->patientAssignments($request)->findOrFail($assignmentId);
            ProviderClientLink::active()->where('user_id', $request->user()->id)->where('provider_id', $assignment->provider_id)->lockForUpdate()->firstOrFail();
            $assignment = $this->patientAssignments($request)->lockForUpdate()->findOrFail($assignmentId);
            $assignment->update(['status' => $data['status'], 'completed_at' => $data['status'] === 'completed' ? ($assignment->completed_at ?? now()) : null]);

            return response()->json(['assignment' => $assignment->load(['resource', 'provider:id,name,practice'])]);
        });
    }

    private function patientAssignments(Request $request)
    {
        return ProviderAssignment::where('user_id', $request->user()->id)->whereIn('provider_id',
            ProviderClientLink::active()->where('user_id', $request->user()->id)->select('provider_id'));
    }
}
