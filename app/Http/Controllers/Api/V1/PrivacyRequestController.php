<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\CustomerCloudExport;
use App\Services\CustomerPrivacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PrivacyRequestController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json(['data' => PrivacyRequest::where('user_id', $request->user()->id)->latest()->paginate(20, ['*'], 'page', $data['page'] ?? 1)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, CustomerPrivacy $privacy)
    {
        $data = $request->validate(['kind' => 'required|in:export,correction,deletion', 'request_id' => 'required|uuid',
            'password' => 'required|string|max:255', 'confirmed' => 'required|accepted', 'changes' => 'sometimes|array']);
        $privacy->verify($request->user(), $data['password']);
        $changes = $data['kind'] === 'correction' ? $privacy->validateChanges($request->user(), $data['changes'] ?? []) : [];
        $record = DB::transaction(function () use ($request, $data, $changes, $privacy) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $privacy->verify($user, $data['password']);
            $record = PrivacyRequest::where('user_id', $user->id)->where('request_id', $data['request_id'])->first();
            if ($record) {
                abort_unless($record->kind === $data['kind'] && ($record->changes ?? []) === $changes, 409, 'This request has already been used for another action.');

                return $record;
            }

            return PrivacyRequest::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'request_id' => $data['request_id'],
                'kind' => $data['kind'], 'status' => 'pending', 'changes' => $changes,
                'verified_at' => now(), 'identity_fingerprint' => $privacy->fingerprint($user)]);
        }, 3);

        return response()->json(['data' => $record], 201)->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, string $id, CustomerPrivacy $privacy, CustomerCloudExport $export)
    {
        $data = $request->validate(['password' => 'required|string|max:255']);
        $record = PrivacyRequest::where('user_id', $request->user()->id)->findOrFail($id);
        abort_unless($record->kind === 'export' && $record->status === 'completed', 409, 'This export has not been approved.');
        $privacy->verify($request->user(), $data['password']);
        abort_unless(hash_equals($record->identity_fingerprint, $privacy->fingerprint($request->user())), 409, 'Your identity changed. Submit a new verified request.');
        try {
            $path = $export->archive($request->user());
        } catch (\Throwable $error) {
            Log::warning('Customer export preparation failed.', ['exception_type' => class_basename($error)]);

            return response()->json(['message' => 'The export could not be prepared. Please retry.'], 503)->header('Cache-Control', 'private, no-store');
        }
        register_shutdown_function(static function () use ($path) {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return response()->streamDownload(function () use ($path) {
            try {
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, 'cozy-account-data.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
