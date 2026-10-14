<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Services\{AdminAudit, AuthSessions, Apple\AppleTokenService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class AdminUsersController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = User::where('role', 'user')->where('is_demo', false)->select('id', 'name', 'email', 'is_active', 'email_verified_at', 'created_at');
        if (! empty($data['search'])) $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('email', 'like', '%'.$data['search'].'%'));
        return response()->json(['data' => $query->orderBy('id')->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
    public function update(Request $request, string $id)
    {
        $user = User::where('role', 'user')->where('is_demo', false)->findOrFail($id);
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($id)], 'is_active' => 'required|boolean']);
        DB::transaction(function () use ($user, $data, $request, $id) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($user->email !== $data['email']) {
                app(AuthSessions::class)->revoke($user);
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->forceFill(['email_verified_at' => null, 'email_invalid' => false, 'email_unsubscribed_at' => null]);
            }
            $user->fill($data)->save();
            if (! $data['is_active']) app(AuthSessions::class)->revoke($user);
            AdminAudit::record($request->user()->id, 'update', 'users', $id, array_keys($data));
        });
        return response()->json(['message' => 'User updated.']);
    }
    public function destroy(Request $request, string $id, AppleTokenService $apple)
    {
        $request->validate(['confirmation' => ['required', Rule::in(['DELETE'])]]);
        $user = User::where('role', 'user')->where('is_demo', false)->findOrFail($id);
        $actor = $request->user()->id;
        // Reuse the patient deletion service, including provider revocation and community cleanup.
        $patientRequest = Request::create('/api/v1/account', 'DELETE');
        $patientRequest->setUserResolver(fn () => $user);
        $response = app(\App\Http\Controllers\Api\V1\AccountController::class)->destroy($patientRequest, $apple);
        AdminAudit::record($actor, 'delete', 'users', $id);
        return $response;
    }
}
