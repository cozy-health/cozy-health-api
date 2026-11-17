<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminAudit;
use App\Services\DevelopmentTestAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class AdminDevelopmentAccessController extends Controller
{
    private function available(): void { abort_unless(app(DevelopmentTestAccess::class)->enabled(), 404); }

    public function index(Request $request)
    {
        $this->available();
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $rows = DB::table('development_access_grants as grants')->join('users', 'users.id', '=', 'grants.user_id')
            ->whereNull('users.deleted_at')->where('users.role', 'user')
            ->select('grants.id', 'grants.plan', 'grants.starts_at', 'grants.expires_at', 'grants.revoked_at', 'users.email')
            ->orderByDesc('grants.created_at')->orderByDesc('grants.id')->paginate(20);
        $rows->through(function ($row) {
            $row->status = $row->revoked_at ? 'revoked' : (Carbon::parse($row->expires_at)->isFuture() ? 'active' : 'expired');
            $row->starts_at = Carbon::parse($row->starts_at)->toIso8601String();
            $row->expires_at = Carbon::parse($row->expires_at)->toIso8601String();
            $row->revoked_at = $row->revoked_at ? Carbon::parse($row->revoked_at)->toIso8601String() : null;
            return $row;
        });
        return response()->json(['data' => $rows, 'development_only' => true])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $this->available();
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['email' => 'required|email:rfc|max:190', 'plan' => 'required|in:student,standard,premium',
            'duration_days' => 'required|integer|min:1|max:30', 'reason' => 'required|string|min:5|max:500']);
        $grant = DB::transaction(function () use ($request, $data) {
            $user = User::where('email', $data['email'])->where('role', 'user')->where('is_active', true)->lockForUpdate()->first();
            abort_unless($user, 422, 'Choose an existing active consumer account in development.');
            abort_if(DB::table('development_access_grants')->where('user_id', $user->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 409, 'This account already has test access. Revoke it before granting another plan.');
            $id = (string) Str::uuid();
            $startsAt = now()->startOfSecond();
            $expiresAt = $startsAt->copy()->addDays($data['duration_days']);
            DB::table('development_access_grants')->insert(['id' => $id, 'user_id' => $user->id, 'plan' => $data['plan'],
                'reason' => encrypt($data['reason']), 'granted_by' => $request->user()->id, 'starts_at' => $startsAt,
                'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'grant-test-access', 'development-access', $id, ['plan', 'expires_at']);
            return ['id' => $id, 'plan' => $data['plan'], 'expires_at' => $expiresAt->toIso8601String()];
        });
        return response()->json(['data' => $grant, 'development_only' => true, 'message' => 'Development test access granted. No payment collected.'], 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $id)
    {
        $this->available();
        DB::transaction(function () use ($request, $id) {
            $grant = DB::table('development_access_grants')->where('id', $id)->firstOrFail();
            User::whereKey($grant->user_id)->lockForUpdate()->firstOrFail();
            $grant = DB::table('development_access_grants')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($grant->revoked_at) return;
            DB::table('development_access_grants')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'revoke-test-access', 'development-access', $id, ['revoked_at']);
        });
        return response()->json(['message' => 'Development test access revoked.'])->header('Cache-Control', 'private, no-store');
    }
}
