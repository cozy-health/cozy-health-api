<?php

namespace App\Http\Controllers;

use App\Services\{AdminAudit, CommunityModeration};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

class CommunityModerationController extends Controller
{
    public function status(Request $request, CommunityModeration $moderation)
    {
        $user = $request->user()->id;
        $moderation->expire($user);
        $ban = $moderation->active($user);
        return response()->json(['data' => ['active_ban' => $ban ? ['id' => $ban->id, 'kind' => 'ban', 'reason' => $ban->reason, 'expires_at' => $ban->expires_at] : null,
            'decisions' => DB::table('community_decisions')->where('user_id', $user)->orderByDesc('created_at')->limit(100)->get(['id', 'kind', 'target_id', 'reason', 'status', 'expires_at', 'created_at']),
            'appeals' => DB::table('community_appeals')->where('user_id', $user)->orderByDesc('created_at')->limit(100)->get()->map(fn ($row) => $this->appealPayload($row, false))]])->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, string $id, CommunityModeration $moderation)
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:5', 'max:2000', 'regex:/\S/u']]);
        return response()->json(['data' => $this->appealPayload($moderation->appeal($request->user()->id, $id, $data['message']), false)])->header('Cache-Control', 'private, no-store');
    }

    public function queue(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'appeals_page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50', 'user_id' => 'sometimes|integer|min:1']);
        $decisions = DB::table('community_decisions')->orderByDesc('created_at')->orderBy('id');
        if (isset($data['user_id'])) $decisions->where('user_id', $data['user_id']);
        $rows = $decisions->paginate($data['per_page'] ?? 20);
        $appeals = DB::table('community_appeals')->where('status', 'pending')->orderBy('created_at')->orderBy('id')->paginate(20, ['*'], 'appeals_page');
        $appeals->through(fn ($row) => $this->appealPayload($row, true));
        AdminAudit::record($request->user()->id, 'review-list', 'community-moderation', 'list');
        return response()->json(['data' => $rows, 'appeals' => $appeals])->header('Cache-Control', 'private, no-store');
    }

    public function ban(Request $request, CommunityModeration $moderation)
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'duration' => 'required|in:24h,7d,30d,permanent', 'reason' => ['required', 'string', 'min:5', 'max:1000', 'regex:/\S/u']]);
        return response()->json(['data' => $moderation->ban($request->user()->id, $data['user_id'], $data['duration'], $data['reason'])], 201);
    }

    public function lift(Request $request, string $id, CommunityModeration $moderation)
    {
        $data = $request->validate(['expected_version' => 'required|uuid', 'reason' => ['required', 'string', 'min:5', 'max:1000', 'regex:/\S/u']]);
        $moderation->lift($request->user()->id, $id, $data['expected_version'], $data['reason']);
        return response()->json(['message' => 'Community restriction lifted.']);
    }

    public function review(Request $request, string $id, CommunityModeration $moderation)
    {
        $data = $request->validate(['expected_version' => 'required|uuid', 'decision' => 'required|in:accepted,rejected',
            'reason' => ['required', 'string', 'min:5', 'max:1000', 'regex:/\S/u'], 'confirm_republish' => 'sometimes|boolean']);
        $moderation->review($request->user()->id, $id, $data['expected_version'], $data['decision'], $data['reason'], $data['confirm_republish'] ?? false);
        return response()->json(['message' => 'Appeal reviewed.']);
    }

    private function appealPayload(object $row, bool $admin): array
    {
        $decision = $admin ? DB::table('community_decisions')->where('id', $row->decision_id)->first(['id', 'kind', 'target_id', 'reason', 'status']) : null;
        return ['id' => $row->id, 'decision_id' => $row->decision_id, 'status' => $row->status, 'message' => Crypt::decryptString($row->message),
            'outcome' => $row->outcome ? Crypt::decryptString($row->outcome) : null, 'created_at' => $row->created_at,
            ...($admin ? ['user_id' => $row->user_id, 'version' => $row->version, 'decision' => $decision] : [])];
    }
}
