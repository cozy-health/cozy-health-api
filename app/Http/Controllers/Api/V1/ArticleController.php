<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContentArticle;
use App\Models\SavedArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleController extends ApiController
{
    private const CATEGORIES = ['anxiety', 'depression', 'sleep', 'relationships', 'self-care', 'work'];

    public function index(Request $request)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'q' => ['sometimes', 'string', 'min:1', 'max:100']]);
        $query = ContentArticle::published();
        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (isset($data['q'])) {
            // Match literal search text; '%' and '_' are not query wildcards.
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['q']).'%';
            $query->where(fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("excerpt LIKE ? ESCAPE '!'", [$needle]));
        }

        // No reading analytics or per-patient recommendation ranking.
        return $this->success($query->orderByDesc('published_at')->orderBy('id')->paginate($data['per_page'] ?? 20));
    }

    public function search(Request $request)
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        return $this->index($request);
    }

    public function categories()
    {
        $counts = ContentArticle::published()->selectRaw('category, COUNT(*) AS total')->groupBy('category')->pluck('total', 'category');

        return $this->success(collect(self::CATEGORIES)->map(fn ($slug) => [
            'slug' => $slug, 'name' => $slug === 'self-care' ? 'Self-care' : ucfirst($slug),
            'article_count' => (int) ($counts[$slug] ?? 0),
        ]));
    }

    private function article(string $reference): ContentArticle
    {
        return ContentArticle::published()->where(fn ($q) => $q->where('id', $reference)->orWhere('slug', $reference))->firstOrFail();
    }

    public function show(string $slug)
    {
        return $this->success($this->article($slug));
    }

    public function bookmark(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $article = $this->article($id);
            $saved = SavedArticle::withTrashed()->where('user_id', $request->user()->id)
                ->where('article_id', $article->id)->first();
            if ($saved && ! $saved->trashed()) {
                return $this->success($saved);
            }
            $saved ??= new SavedArticle(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'article_id' => $article->id]);
            $saved->fill(['article_slug' => $article->slug, 'title' => $article->title,
                'category' => $article->category, 'read_time_minutes' => $article->read_time_minutes,
                'excerpt' => $article->excerpt, 'body' => $article->body,
                'saved_at' => now(), 'client_created_at' => now()]);
            $saved->deleted_at = null;
            $saved->save();

            return $this->success($saved);
        });
    }

    public function unbookmark(Request $request, string $id)
    {
        // Allow removal even if an article was unpublished/deleted afterwards.
        SavedArticle::where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->where('article_id', $id)->orWhere('article_slug', $id))->delete();

        return response()->json(['message' => 'Bookmark removed.']);
    }
}
