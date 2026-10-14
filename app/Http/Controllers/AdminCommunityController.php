<?php
namespace App\Http\Controllers;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AdminCommunityController extends Controller
{
    public function index(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['posts', 'comments', 'members'], true), 404);
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'group_id' => 'sometimes|integer|exists:community_groups,id', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        if ($kind === 'members') {
            $query = DB::table('community_memberships')->join('users', 'users.id', '=', 'community_memberships.user_id')->select('community_memberships.id', 'community_memberships.group_id', 'users.name', 'community_memberships.created_at')->whereNull('users.deleted_at');
            if (isset($data['group_id'])) $query->where('group_id', $data['group_id']);
            if (!empty($data['search'])) $query->where('users.name', 'like', '%'.$data['search'].'%');
            $query->orderBy('community_memberships.id');
        } else {
            $table = 'community_'.$kind;
            $query = DB::table($table)->whereNull('deleted_at')->select('id', 'body', 'status', 'is_anonymous', 'created_at');
            if ($kind === 'posts') { $query->addSelect('group_id'); if (isset($data['group_id'])) $query->where('group_id', $data['group_id']); }
            else $query->addSelect('post_id');
            if (!empty($data['search'])) $query->where('body', 'like', '%'.$data['search'].'%');
            $query->orderByDesc('created_at');
        }
        AdminAudit::record($request->user()->id, 'review-list', 'community-'.$kind, 'list');
        return response()->json(['data' => $query->paginate($data['per_page'] ?? 10)])->header('Cache-Control', 'private, no-store');
    }
    public function update(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['posts', 'comments'], true), 404);
        $data = $request->validate(['status' => 'required|in:published,removed,pending']);
        DB::transaction(function () use ($request, $kind, $id, $data) {
            $table = 'community_'.$kind;
            DB::table($table)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            DB::table($table)->where('id', $id)->update([...$data, 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'moderate', 'community-'.$kind, $id, ['status']);
        });
        return response()->json(['message' => 'Moderation saved.']);
    }
    public function removeMember(Request $request, string $id)
    {
        DB::transaction(function () use ($request, $id) {
            DB::table('community_memberships')->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('community_memberships')->where('id', $id)->delete();
            AdminAudit::record($request->user()->id, 'remove-member', 'community-members', $id);
        });
        return response()->json(['message' => 'Membership removed.']);
    }
}
