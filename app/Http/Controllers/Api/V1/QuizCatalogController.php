<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PaginationRequest;
use App\Models\{QuizAttempt, QuizDefinition, QuizSession};
use App\Services\AssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuizCatalogController extends ApiController
{
    public function __construct(private AssessmentService $assessments) {}
    public function index(PaginationRequest $request)
    {
        $this->assessments->enabled();
        $definitions = collect();
        foreach ($this->assessments->catalog()->with('questions.options')->orderBy('sort_order')->orderBy('id')->get() as $quiz) {
            try { $version = $this->assessments->publish($quiz); $definitions->push([...$version->definition, 'definition_version' => $version->id]); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if (! in_array($error->getStatusCode(), [410, 503], true)) throw $error;
            }
        }
        $perPage = (int) $request->input('per_page', 20);
        $page = max(1, (int) $request->input('page', 1));
        return $this->success(new \Illuminate\Pagination\LengthAwarePaginator($definitions->forPage($page, $perPage)->values(), $definitions->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]));
    }
    public function show(string $slug)
    {
        $this->assessments->enabled();
        $version = $this->assessments->publish($this->assessments->catalog()->where('slug', $slug)->firstOrFail());
        return $this->success([...$version->definition, 'definition_version' => $version->id]);
    }
    public function version(string $id)
    {
        $version = QuizDefinition::whereNull('revoked_at')->findOrFail($id);
        $this->assessments->catalog()->findOrFail($version->quiz_id);
        return $this->success([...$version->definition, 'definition_version' => $version->id]);
    }
    public function start(Request $request, string $id)
    {
        $this->assessments->enabled();
        $request->validate(['disclaimer_accepted' => ['required', 'accepted']]);
        $version = $this->assessments->publish($this->assessments->catalog()->findOrFail($id));
        $definition = [...$version->definition, 'definition_version' => $version->id];
        $session = QuizSession::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id,
            'quiz_id' => $id, 'definition' => $definition, 'expires_at' => now()->addDays(7)]);
        return $this->success(['attempt_id' => $session->id, 'expires_at' => $session->expires_at, 'quiz' => $definition]);
    }
    public function submit(Request $request, string $id)
    {
        $request->validate(['attempt_id' => 'required|uuid']);
        $session = QuizSession::where('user_id', $request->user()->id)->where('quiz_id', $id)->findOrFail($request->attempt_id);
        if (! QuizAttempt::withTrashed()->whereKey($session->id)->exists()) abort_if($session->expires_at->isPast(), 410, 'Session expired.');
        return $this->success($this->assessments->submit($request->user(), [...$request->all(),
            'id' => $session->id, 'definition_version' => $session->definition['definition_version'],
            'disclaimer_accepted' => true], $session->definition));
    }
    public function offline(Request $request) { return $this->success($this->assessments->submit($request->user(), $request->all())); }
    public function attempts(PaginationRequest $request)
    {
        $query = QuizAttempt::where('user_id', $request->user()->id);
        if ($request->boolean('include_deleted')) $query->withTrashed();
        else $query->where('is_deleted', false);
        return $this->success($query->orderByDesc('completed_at')->orderBy('id')->paginate($request->input('per_page', 20)));
    }
    public function attempt(Request $request, string $id)
    {
        return $this->success(QuizAttempt::where('user_id', $request->user()->id)->where('is_deleted', false)->findOrFail($id));
    }
}
