<?php
namespace App\Http\Controllers;
use App\Models\{Quiz, QuizQuestion, QuizOption};
use App\Services\{AdminAudit, AssessmentService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class AdminQuizController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,20,50']);
        $query = DB::table('admin_quiz_drafts');
        if (!empty($data['search'])) $query->where('title', 'like', '%'.$data['search'].'%');
        $rows = $query->orderByDesc('id')->paginate($data['per_page'] ?? 10);
        $rows->through(function ($row) { $row->version = $this->version($row); $row->definition = json_decode($row->definition, true); return $row; });
        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }
    public function store(Request $request) { return $this->save($request); }
    public function update(Request $request, string $id) { return $this->save($request, $id); }
    private function definition(Request $request): array
    {
        $data = $request->validate(['title' => 'required|string|max:100', 'definition' => 'required|array', 'definition.title' => 'required|string|max:100', 'definition.slug' => 'required|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|max:100',
            'definition.type' => 'required|string|max:50', 'definition.description' => 'nullable|string|max:5000', 'definition.estimated_minutes' => 'required|integer|min:1|max:120',
            'definition.disclaimer' => 'required|string|max:5000', 'definition.scoring_rules' => 'required|array', 'definition.scoring_rules.timeframe' => 'required|string|max:200',
            'definition.scoring_rules.bands' => 'required|array|min:1|max:50', 'definition.scoring_rules.bands.*.max' => 'required|integer|min:0|max:10000',
            'definition.scoring_rules.bands.*.label' => 'required|string|max:50', 'definition.scoring_rules.bands.*.recommendation' => 'nullable|string|max:2000',
            'definition.scoring_rules.crisis_threshold' => 'sometimes|integer|min:0|max:10000', 'definition.questions' => 'required|array|list|min:1|max:100',
            'definition.scoring_rules.recommendation' => 'sometimes|nullable|string|max:2000', 'definition.sort_order' => 'sometimes|integer|min:0|max:10000',
            'definition.scoring_rules.provenance' => 'sometimes|array:license,source_url,approval_reference',
            'definition.scoring_rules.provenance.license' => 'sometimes|nullable|string|max:2000',
            'definition.scoring_rules.provenance.source_url' => 'sometimes|nullable|url:https|max:2000',
            'definition.scoring_rules.provenance.approval_reference' => 'sometimes|nullable|string|max:2000',
            'definition.questions.*.question' => 'required|string|max:2000', 'definition.questions.*.options' => 'required|array|list|min:2|max:10',
            'definition.questions.*.options.*.label' => 'required|string|max:500', 'definition.questions.*.options.*.score' => 'required|integer|min:0|max:100']);
        $definition = $data['definition']; $definition['title'] = $data['title'];
        // IDs in imported files are not trusted. They are generated on publication.
        foreach ($definition['questions'] as $q => $question) foreach ($question['options'] ?? [] as $i => $option) $definition['questions'][$q]['options'][$i]['id'] = $i + 1;
        try { app(AssessmentService::class)->validateDefinition($definition); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { throw \Illuminate\Validation\ValidationException::withMessages(['definition' => $error->getMessage()]); }
        return $definition;
    }
    public function preview(Request $request)
    {
        $request->merge(['title' => $request->input('definition.title')]);
        $definition = $this->definition($request);
        $data = $request->validate(['answers' => 'required|array|list|min:1|max:100', 'answers.*' => 'required|integer|min:0|max:9']);
        $answers = array_map(fn ($index) => $index + 1, $data['answers']);
        $result = app(AssessmentService::class)->score($definition, $answers);
        // Preview is ephemeral: no attempts, audit answers or analytics.
        return response()->json(['data' => [
            ...\Illuminate\Support\Arr::only($result, ['score', 'maximum_score', 'interpretation', 'is_crisis_flagged']),
            'recommendation' => $result['result_snapshot']['recommendation'], 'disclaimer' => $definition['disclaimer'],
        ]])->header('Cache-Control', 'private, no-store');
    }
    private function save(Request $request, ?string $id = null)
    {
        $definition = $this->definition($request);
        $data = ['title' => $definition['title']];
        $key = DB::transaction(function () use ($request, $id, $definition, $data) {
            if ($id) {
                $draft = DB::table('admin_quiz_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_if($draft->status === 'archived', 422, 'Archived drafts cannot be edited.');
                $this->check($request, $draft);
                DB::table('admin_quiz_drafts')->where('id', $id)->update(['title' => $data['title'], 'definition' => json_encode($definition), 'status' => 'draft', 'updated_at' => now()]);
                $key = $id;
            } else $key = DB::table('admin_quiz_drafts')->insertGetId(['title' => $data['title'], 'definition' => json_encode($definition), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'save-draft', 'quizzes', (string) $key, ['definition']);
            return $key;
        });
        return response()->json(['data' => ['id' => $key, 'version' => $this->version(DB::table('admin_quiz_drafts')->where('id', $key)->firstOrFail())]], $id ? 200 : 201)
            ->header('Cache-Control', 'private, no-store');
    }
    public function publish(Request $request, string $id)
    {
        $version = DB::transaction(function () use ($request, $id) {
            $draft = DB::table('admin_quiz_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($draft->status === 'draft', 409, 'Only drafts can be published.');
            $this->check($request, $draft);
            $request->validate(['approved' => 'required|accepted']);
            $definition = json_decode($draft->definition, true);
            \Illuminate\Support\Facades\Validator::make($definition, [
                'scoring_rules.provenance.license' => 'required|string|max:2000',
                'scoring_rules.provenance.approval_reference' => 'required|string|max:2000',
            ])->validate();
            app(AssessmentService::class)->validateDefinition($definition);
            $quiz = $draft->quiz_id ? Quiz::whereKey($draft->quiz_id)->lockForUpdate()->firstOrFail() : new Quiz;
            abort_if($quiz->exists && $quiz->slug !== $definition['slug'], 422, 'An existing quiz key cannot change.');
            abort_if(Quiz::where('slug', $definition['slug'])->when($quiz->exists, fn ($q) => $q->where('id', '!=', $quiz->id))->exists(), 422, 'This quiz key already exists.');
            $quiz->fill(['title' => $definition['title'], 'slug' => $definition['slug'], 'type' => $definition['type'] ?? 'wellness', 'description' => $definition['description'] ?? '', 'estimated_minutes' => $definition['estimated_minutes'] ?? 5, 'sort_order' => $definition['sort_order'] ?? 0, 'is_active' => true, 'approved_at' => now(), 'scoring_rules' => $definition['scoring_rules'], 'disclaimer' => $definition['disclaimer']])->save();
            $quiz->questions()->get()->each(fn ($q) => $q->delete());
            foreach ($definition['questions'] as $i => $question) {
                $q = $quiz->questions()->create(['question' => $question['question'], 'sort_order' => $i]);
                foreach ($question['options'] as $j => $option) $q->options()->create(['label' => $option['label'], 'value' => (string) $j, 'score' => $option['score'], 'sort_order' => $j]);
            }
            $version = app(AssessmentService::class)->publish($quiz);
            DB::table('admin_quiz_drafts')->where('id', $id)->update(['quiz_id' => $quiz->id, 'status' => 'published', 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'publish', 'quizzes', $id, ['definition']);
            return $version;
        });
        return response()->json(['data' => ['definition_version' => $version->id]]);
    }
    public function archive(Request $request, string $id)
    {
        DB::transaction(function () use ($request, $id) {
            $draft = DB::table('admin_quiz_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->check($request, $draft);
            if ($draft->quiz_id) Quiz::whereKey($draft->quiz_id)->update(['is_active' => false]);
            DB::table('admin_quiz_drafts')->where('id', $id)->update(['status' => 'archived', 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'archive', 'quizzes', $id);
        });
        return response()->json(['message' => 'Archived.']);
    }
    private function version(object $draft): string
    {
        return hash('sha256', json_encode([$draft->id, $draft->title, $draft->definition, $draft->status, $draft->quiz_id, $draft->updated_at], JSON_THROW_ON_ERROR));
    }
    private function check(Request $request, object $draft): void
    {
        $data = $request->validate(['expected_version' => 'required|string|size:64']);
        abort_unless(hash_equals($this->version($draft), $data['expected_version']), 409, 'This assessment changed. Reload the list and reopen it before trying again.');
    }
}
