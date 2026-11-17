<?php

namespace App\Http\Controllers;

use App\Models\ContentArticle;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminArticleController extends Controller
{
    private const FIELDS = ['title', 'slug', 'category', 'excerpt', 'body', 'read_time_minutes', 'license', 'source_url'];

    private function version(ContentArticle $article): string
    {
        return hash('sha256', json_encode($article->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    private function present(ContentArticle $article): array
    {
        return ['id' => $article->id, 'version' => $this->version($article),
            'published' => $article->approved_at !== null && filled($article->license) && $article->published_at !== null && $article->published_at->lte(now()),
            'has_draft' => $article->draft_payload !== null,
            'draft' => $article->draft_payload ?? [...Arr::only($article->getAttributes(), self::FIELDS), 'read_time_minutes' => $article->read_time_minutes]];
    }

    private function fields(array $data, ?ContentArticle $article = null, bool $publishing = false): array
    {
        $validated = Validator::make($data, [
            'title' => 'required|string|max:255', 'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('content_articles', 'slug')->ignore($article?->id)],
            'category' => 'required|in:anxiety,depression,sleep,relationships,self-care,work',
            'excerpt' => 'required|string|max:1000', 'body' => 'required|string|max:50000',
            'read_time_minutes' => 'required|integer|min:1|max:180',
            'license' => ($publishing ? 'required' : 'nullable').'|string|max:255',
            'source_url' => ['nullable', 'url:https', 'max:1000'],
        ])->validate();
        abort_if($article?->approved_at && $validated['slug'] !== $article->slug, 422, 'A previously published article key cannot change.');
        return [...$validated, 'read_time_minutes' => (int) $validated['read_time_minutes'], 'license' => $validated['license'] ?? null, 'source_url' => $validated['source_url'] ?? null];
    }

    private function check(ContentArticle $article, Request $request): void
    {
        $data = $request->validate(['expected_version' => 'required|string|size:64']);
        abort_unless(hash_equals($this->version($article), $data['expected_version']), 409, 'This article changed. Reload before saving.');
    }

    private function result(ContentArticle $article, int $status = 200)
    {
        return response()->json(['data' => $this->present($article->fresh())], $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = ContentArticle::query();
        if (filled($data['search'] ?? null)) $query->where(fn ($q) => $q->where('title', 'like', '%'.$data['search'].'%')->orWhere('draft_payload->title', 'like', '%'.$data['search'].'%'));
        $rows = $query->orderByDesc('updated_at')->orderBy('id')->paginate($data['per_page'] ?? 10);
        $rows->through(fn ($article) => $this->present($article));
        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $fields = $this->fields($request->all());
        $article = DB::transaction(function () use ($request, $fields) {
            $article = ContentArticle::create([...$fields, 'id' => (string) Str::uuid(), 'draft_payload' => $fields]);
            AdminAudit::record($request->user()->id, 'save-draft', 'articles', $article->id, array_keys($fields));
            return $article;
        });
        return $this->result($article, 201);
    }

    public function show(string $id)
    {
        return $this->result(ContentArticle::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $article = DB::transaction(function () use ($request, $id) {
            $article = ContentArticle::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check($article, $request);
            $article->draft_payload = $this->fields($request->all(), $article);
            $article->save();
            AdminAudit::record($request->user()->id, 'save-draft', 'articles', $id, self::FIELDS);
            return $article;
        });
        return $this->result($article);
    }

    public function publish(Request $request, string $id)
    {
        $request->validate(['approved' => 'required|accepted']);
        $article = DB::transaction(function () use ($request, $id) {
            $article = ContentArticle::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check($article, $request);
            $fields = $this->fields($article->draft_payload ?? Arr::only($article->getAttributes(), self::FIELDS), $article, true);
            $article->fill([...$fields, 'approved_at' => now(), 'published_at' => now(), 'draft_payload' => null])->save();
            AdminAudit::record($request->user()->id, 'publish', 'articles', $id, self::FIELDS);
            return $article;
        });
        return $this->result($article);
    }

    public function unpublish(Request $request, string $id)
    {
        $article = DB::transaction(function () use ($request, $id) {
            $article = ContentArticle::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check($article, $request);
            $article->published_at = null;
            $article->save();
            AdminAudit::record($request->user()->id, 'unpublish', 'articles', $id, ['published_at']);
            return $article;
        });
        return $this->result($article);
    }
}
