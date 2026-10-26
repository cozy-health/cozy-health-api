<?php
namespace App\Http\Controllers;
use App\Services\{AdminAudit, AdminContentVersion};
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
            $query = DB::table($table)->whereNull('deleted_at')->select('id', 'body', 'status', 'is_anonymous', 'created_at', 'updated_at');
            if ($kind === 'posts') { $query->addSelect('group_id', 'image_object_key'); if (isset($data['group_id'])) $query->where('group_id', $data['group_id']); }
            else $query->addSelect('post_id');
            if (!empty($data['search'])) $query->where('body', 'like', '%'.$data['search'].'%');
            $query->orderByDesc('created_at');
        }
        AdminAudit::record($request->user()->id, 'review-list', 'community-'.$kind, 'list');
        $rows = $query->paginate($data['per_page'] ?? 10);
        if ($kind !== 'members') $rows->through(function ($row) { $row->version = AdminContentVersion::token($row); $row->image_url = app(\App\Services\CommunityImageService::class)->url($row->image_object_key ?? null); unset($row->image_object_key); return $row; });
        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }
    public function show(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['posts', 'comments'], true), 404);
        $row = DB::table('community_'.$kind)->where('id', $id)->whereNull('deleted_at')->firstOrFail(['id', 'body', 'status', 'is_anonymous', 'created_at', 'updated_at', ...($kind === 'posts' ? ['image_object_key'] : [])]);
        $row->version = AdminContentVersion::token($row);
        $row->image_url = app(\App\Services\CommunityImageService::class)->url($row->image_object_key ?? null);
        unset($row->image_object_key);
        AdminAudit::record($request->user()->id, 'review-content', 'community-'.$kind, $id);
        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['posts', 'comments'], true), 404);
        $data = $request->validate(['status' => 'required|in:published,removed,pending', 'expected_version' => 'required|string|size:64', 'confirm_republish' => 'sometimes|boolean']);
        DB::transaction(function () use ($request, $kind, $id, $data) {
            $table = 'community_'.$kind;
            $content = DB::table($table)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            AdminContentVersion::check($content, $data, $data['status']);
            DB::table($table)->where('id', $id)->update(['status' => $data['status'], 'updated_at' => now()]);
            if (in_array($data['status'], ['published', 'removed'], true)) {
                \App\Services\AdminReportResolution::resolvePending(
                    $request->user()->id, $kind === 'posts' ? 'post' : 'comment', $id,
                    $data['status'] === 'published' ? 'publish' : 'remove'
                );
            }
            if ($kind === 'comments' && $data['status'] === 'published') {
                $comment = DB::table($table)->where('id', $id)->first();
                $post = DB::table('community_posts')->where('id', $comment->post_id)->first();
                if ($post) app(\App\Services\CommunityNotifications::class)->create('reply', $post, $comment->user_id, $comment);
            }
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
