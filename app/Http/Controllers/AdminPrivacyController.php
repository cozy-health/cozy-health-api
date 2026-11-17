<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPrivacyDeletion;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\CustomerPrivacy;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPrivacyController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'status' => 'sometimes|in:pending,processing,held,completed,declined,failed']);
        $query = PrivacyRequest::query()->select(['id', 'user_id', 'kind', 'status', 'version', 'created_at', 'updated_at', 'outcome']);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json(['data' => $query->latest()->paginate(20, ['*'], 'page', $data['page'] ?? 1)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id)
    {
        $record = PrivacyRequest::findOrFail($id);
        AdminAudit::record($request->user()->id, 'read', 'privacy-requests', $id);

        return response()->json(['data' => $record])->header('Cache-Control', 'private, no-store');
    }

    public function process(Request $request, string $id, CustomerPrivacy $privacy)
    {
        $data = $request->validate(['decision' => 'required|in:approve,hold,decline', 'expected_version' => 'required|integer|min:1', 'outcome' => 'required|string|max:1000']);
        $result = DB::transaction(function () use ($request, $id, $data, $privacy) {
            // Lock the customer before request state; matches submission/account deletion lock order.
            $candidate = PrivacyRequest::findOrFail($id);
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $record = PrivacyRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($record->version === $data['expected_version'] && in_array($record->status, ['pending', 'held', 'failed'], true), 409, 'This request changed. Refresh before reviewing.');
            if ($data['decision'] === 'approve') {
                abort_unless($user->is_active && $user->email_verified_at && hash_equals($record->identity_fingerprint, $privacy->fingerprint($user)), 409, 'Customer identity changed. A new verified request is required.');
                if ($record->kind === 'correction') {
                    $privacy->correct($user, $privacy->validateChanges($user, $record->changes ?? []));
                }
                if ($record->kind === 'deletion') {
                    AdminAudit::record($request->user()->id, 'approve-deletion', 'privacy-requests', $record->id);
                    $record->update(['status' => 'processing', 'version' => $record->version + 1, 'outcome' => $data['outcome']]);
                    // The job and state commit together; a process crash cannot lose dispatch.
                    $job = (new ProcessPrivacyDeletion($record->id, (int) $record->version, $request->user()->id))->onConnection('database')->onQueue('default')->beforeCommit();
                    app(Dispatcher::class)->dispatch($job);

                    return $record;
                }
            }
            $record->update(['status' => match ($data['decision']) {
                'approve' => 'completed', 'hold' => 'held', 'decline' => 'declined'
            },
                'outcome' => $data['outcome'], 'version' => $record->version + 1]);
            AdminAudit::record($request->user()->id, $data['decision'], 'privacy-requests', $id, ['status']);

            return $record;
        }, 3);

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
