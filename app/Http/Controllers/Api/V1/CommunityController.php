<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PaginationRequest;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommunityController extends ApiController
{
    private function visible(Request $request, string $table): Builder
    {
        $me = $request->user()->id;

        return DB::table($table)->join('users', "$table.user_id", '=', 'users.id')
            ->select("$table.*", 'users.name as author_name')->whereNull("$table.deleted_at")
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->where(fn ($q) => $q->where("$table.status", 'published')->orWhere("$table.user_id", $me))
            ->whereNotIn("$table.user_id", DB::table('community_blocks')->where('user_id', $me)->select('blocked_user_id'))
            ->whereNotIn("$table.user_id", DB::table('community_blocks')->where('blocked_user_id', $me)->select('user_id'));
    }

    private function posts(Request $request): Builder
    {
        return $this->visible($request, 'community_posts')
            ->where(fn ($q) => $q->whereNull('community_posts.group_id')->orWhereIn('community_posts.group_id',
                DB::table('community_memberships')->where('user_id', $request->user()->id)->select('group_id')))
            ->where(fn ($q) => $q->whereNull('community_posts.group_id')->orWhereIn('community_posts.group_id',
                DB::table('community_groups')->where('is_active', true)->select('id')));
    }

    private function post(Request $request, string $id): object
    {
        return $this->posts($request)->where('community_posts.id', $id)->firstOrFail();
    }

    private function payload(Request $request, object $row): array
    {
        $owner = (int) $row->user_id === (int) $request->user()->id;

        return ['id' => $row->id, 'body' => $row->body,
            'author' => ['id' => $row->is_anonymous ? null : $row->user_id, 'name' => $row->is_anonymous ? 'Anonymous' : $row->author_name],
            'is_anonymous' => (bool) $row->is_anonymous, 'is_owner' => $owner,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ...(isset($row->post_id) ? ['post_id' => $row->post_id, 'parent_id' => $row->parent_id] : ['group_id' => $row->group_id, 'mood' => $row->mood]),
            ...($owner ? ['status' => $row->status, 'crisis_support_recommended' => (bool) $row->is_crisis_flagged] : []),
        ];
    }

    public function index(PaginationRequest $request)
    {
        $page = $this->posts($request)->orderByDesc('community_posts.created_at')->orderByDesc('community_posts.id')
            ->paginate($request->input('per_page', 20));
        $page->through(fn ($row) => $this->payload($request, $row));

        return $this->success($page);
    }

    public function show(Request $request, string $id)
    {
        return $this->success($this->payload($request, $this->post($request, $id)));
    }

    private function textRules(): array
    {
        return ['body' => ['required', 'string', 'max:5000', 'regex:/\S/u'],
            'is_anonymous' => ['sometimes', 'boolean']];
    }

    private function flagged(string $body): bool
    {
        // Conservative review signal only, not a clinical risk assessment.
        return (bool) preg_match('/\b(kill myself|suicide|end my life|hurt myself)\b/iu', $body);
    }

    private function autoReport(string $type, string $id, array $data, bool $review = false): void
    {
        if (! $data['is_crisis_flagged'] && ! $review) {
            return;
        }
        DB::table('community_reports')->insert(['reporter_id' => null, 'target_type' => $type, 'target_id' => $id,
            'reason' => $data['is_crisis_flagged'] ? 'safety' : 'edit_review', 'snapshot' => json_encode($data), 'status' => 'pending',
            'due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([...$this->textRules(), 'mood' => ['sometimes', 'nullable', 'string', 'max:50'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'guidelines_version' => ['required', Rule::in([config('community.guidelines_version')])],
            'guidelines_accepted' => ['required', 'accepted']]);
        if (isset($data['group_id'])) {
            $this->memberGroup($request, (string) $data['group_id']);
        }
        unset($data['guidelines_accepted']);
        $id = (string) Str::uuid();
        $data = [...$data, 'user_id' => $request->user()->id, 'is_crisis_flagged' => $this->flagged($data['body']),
            'guidelines_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        $data['status'] = $data['is_crisis_flagged'] ? 'pending' : 'published';
        DB::transaction(function () use ($id, $data) {
            DB::table('community_posts')->insert(['id' => $id, ...$data]);
            $this->autoReport('post', $id, $data);
        });

        return $this->show($request, $id);
    }

    public function update(Request $request, string $id)
    {
        $this->post($request, $id);
        $existing = $this->owned($request, 'community_posts', $id);
        $data = $request->validate([...$this->textRules(), 'mood' => ['sometimes', 'nullable', 'string', 'max:50']]);
        $data['is_crisis_flagged'] = $this->flagged($data['body']);
        // Edits cannot revive a moderator-removed post or bypass review.
        $data['status'] = $existing->status === 'removed' ? 'removed' : 'pending';
        DB::transaction(function () use ($request, $id, $data) {
            DB::table('community_posts')->where('id', $id)->where('user_id', $request->user()->id)->update([...$data, 'updated_at' => now()]);
            $this->autoReport('post', $id, $data, true);
        });

        return $this->show($request, $id);
    }

    private function owned(Request $request, string $table, string $id): object
    {
        return DB::table($table)->where('id', $id)->where('user_id', $request->user()->id)->whereNull('deleted_at')->firstOrFail();
    }

    public function destroy(Request $request, string $id)
    {
        $this->owned($request, 'community_posts', $id);
        DB::table('community_posts')->where('id', $id)->where('user_id', $request->user()->id)->update(['body' => '', 'deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Post removed.']);
    }

    public function comments(PaginationRequest $request, string $id)
    {
        $this->post($request, $id);
        $page = $this->visible($request, 'community_comments')->where('post_id', $id)
            ->orderBy('community_comments.created_at')->orderBy('community_comments.id')->paginate($request->input('per_page', 20));
        $page->through(fn ($row) => $this->payload($request, $row));

        return $this->success($page);
    }

    public function comment(Request $request, string $id)
    {
        $this->post($request, $id);
        $data = $request->validate([...$this->textRules(), 'parent_id' => ['sometimes', 'nullable', 'uuid']]);
        if (isset($data['parent_id'])) {
            $this->visible($request, 'community_comments')->where('post_id', $id)->where('community_comments.id', $data['parent_id'])->firstOrFail();
        }
        $commentId = (string) Str::uuid();
        $data = [...$data, 'post_id' => $id, 'user_id' => $request->user()->id,
            'is_crisis_flagged' => $this->flagged($data['body']), 'created_at' => now(), 'updated_at' => now()];
        $data['status'] = $data['is_crisis_flagged'] ? 'pending' : 'published';
        DB::transaction(function () use ($commentId, $data) {
            DB::table('community_comments')->insert(['id' => $commentId, ...$data]);
            $this->autoReport('comment', $commentId, $data);
        });
        $row = $this->visible($request, 'community_comments')->where('community_comments.id', $commentId)->firstOrFail();

        return $this->success($this->payload($request, $row));
    }

    public function deleteComment(Request $request, string $id)
    {
        $this->owned($request, 'community_comments', $id);
        DB::table('community_comments')->where('id', $id)->where('user_id', $request->user()->id)->update(['body' => '', 'deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Comment removed.']);
    }

    public function reportPost(Request $request, string $id)
    {
        return $this->report($request, 'post', $id, $this->post($request, $id));
    }

    public function reportComment(Request $request, string $id)
    {
        $row = $this->visible($request, 'community_comments')->where('community_comments.id', $id)->firstOrFail();
        $this->post($request, $row->post_id);

        return $this->report($request, 'comment', $id, $row);
    }

    private function report(Request $request, string $type, string $id, object $row)
    {
        $data = $request->validate(['reason' => ['required', Rule::in(['harassment', 'hate', 'spam', 'safety', 'other'])],
            'details' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        DB::table('community_reports')->insertOrIgnore(['reporter_id' => $request->user()->id, 'target_type' => $type,
            'target_id' => $id, ...$data, 'snapshot' => json_encode((array) $row), 'status' => 'pending',
            'due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Report received for moderation.'], 202);
    }

    public function block(Request $request, string $id)
    {
        abort_unless(ctype_digit($id), 404);
        $target = User::where('is_active', true)->where('role', 'user')->findOrFail($id);
        abort_if((int) $request->user()->id === (int) $target->id, 422, 'You cannot block yourself.');
        DB::table('community_blocks')->insertOrIgnore(['user_id' => $request->user()->id, 'blocked_user_id' => $target->id,
            'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'User blocked.']);
    }

    public function unblock(Request $request, string $id)
    {
        DB::table('community_blocks')->where('user_id', $request->user()->id)->where('blocked_user_id', $id)->delete();

        return response()->json(['message' => 'User unblocked.']);
    }

    public function blocked(PaginationRequest $request)
    {
        return $this->success(DB::table('community_blocks')->join('users', 'blocked_user_id', '=', 'users.id')
            ->where('community_blocks.user_id', $request->user()->id)->whereNull('users.deleted_at')
            ->select('users.id', 'users.name', 'community_blocks.created_at')->orderBy('community_blocks.id')->paginate($request->input('per_page', 20)));
    }

    public function groups(PaginationRequest $request)
    {
        $page = DB::table('community_groups')->where('is_active', true)->orderBy('name')->orderBy('id')->paginate($request->input('per_page', 20));
        $joined = DB::table('community_memberships')->where('user_id', $request->user()->id)->pluck('group_id')->all();
        $page->through(fn ($group) => ['id' => $group->id, 'name' => $group->name, 'description' => $group->description, 'joined' => in_array($group->id, $joined)]);

        return $this->success($page);
    }

    private function memberGroup(Request $request, string $id): void
    {
        DB::table('community_groups')->where('id', $id)->where('is_active', true)->firstOrFail();
        DB::table('community_memberships')->where('user_id', $request->user()->id)->where('group_id', $id)->firstOrFail();
    }

    public function join(Request $request, string $id)
    {
        DB::table('community_groups')->where('id', $id)->where('is_active', true)->firstOrFail();
        DB::table('community_memberships')->insertOrIgnore(['user_id' => $request->user()->id, 'group_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Group joined.']);
    }

    public function leave(Request $request, string $id)
    {
        DB::table('community_memberships')->where('user_id', $request->user()->id)->where('group_id', $id)->delete();

        return response()->json(['message' => 'Group left.']);
    }

    public function groupPosts(PaginationRequest $request, string $id)
    {
        $this->memberGroup($request, $id);
        $page = $this->posts($request)->where('group_id', $id)->orderByDesc('community_posts.created_at')->orderByDesc('community_posts.id')->paginate($request->input('per_page', 20));
        $page->through(fn ($row) => $this->payload($request, $row));

        return $this->success($page);
    }

    public function image(Request $request, string $id)
    {
        $this->owned($request, 'community_posts', $id);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5119', 'dimensions:max_width=4095,max_height=4095']]);

        // No image is persisted or made visible without a configured moderation
        // service and EXIF-stripping pipeline. Phase 21 must supply that pipeline.
        return $this->error('Image moderation is not available. Please post text instead.', 'IMAGE_MODERATION_UNAVAILABLE', [], 503);
    }
}
