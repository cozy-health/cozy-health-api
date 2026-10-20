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
        $rows->through(function ($row) { $row->definition = json_decode($row->definition, true); return $row; });
        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }
    public function store(Request $request) { return $this->save($request); }
    public function update(Request $request, string $id) { return $this->save($request, $id); }
    private function save(Request $request, ?string $id = null)
    {
        $data = $request->validate(['title' => 'required|string|max:100', 'definition' => 'required|array', 'definition.title' => 'required|string|max:100', 'definition.slug' => 'required|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|max:100',
            'definition.type' => 'required|string|max:50', 'definition.description' => 'nullable|string|max:5000', 'definition.estimated_minutes' => 'required|integer|min:1|max:120',
            'definition.disclaimer' => 'required|string|max:5000', 'definition.scoring_rules' => 'required|array', 'definition.scoring_rules.timeframe' => 'required|string|max:200',
            'definition.scoring_rules.bands' => 'required|array|min:1|max:50', 'definition.scoring_rules.bands.*.max' => 'required|integer|min:0|max:10000',
            'definition.scoring_rules.bands.*.label' => 'required|string|max:50', 'definition.scoring_rules.bands.*.recommendation' => 'nullable|string|max:2000',
            'definition.scoring_rules.crisis_threshold' => 'sometimes|integer|min:0|max:10000', 'definition.questions' => 'required|array|min:1|max:100',
            'definition.scoring_rules.recommendation' => 'sometimes|nullable|string|max:2000', 'definition.sort_order' => 'sometimes|integer|min:0|max:10000',
            'definition.questions.*.question' => 'required|string|max:2000', 'definition.questions.*.options' => 'required|array|min:2|max:10',
            'definition.questions.*.options.*.label' => 'required|string|max:500', 'definition.questions.*.options.*.score' => 'required|integer|min:0|max:100']);
        $definition = $data['definition']; $definition['title'] = $data['title'];
        // IDs in imported files are not trusted. They are generated on publication.
        foreach ($definition['questions'] as $q => $question) foreach ($question['options'] ?? [] as $i => $option) $definition['questions'][$q]['options'][$i]['id'] = $i + 1;
        try { app(AssessmentService::class)->validateDefinition($definition); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { throw \Illuminate\Validation\ValidationException::withMessages(['definition' => $error->getMessage()]); }
        $key = DB::transaction(function () use ($request, $id, $definition, $data) {
            if ($id) {
                $draft = DB::table('admin_quiz_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_if($draft->status === 'archived', 422, 'Archived drafts cannot be edited.');
                DB::table('admin_quiz_drafts')->where('id', $id)->update(['title' => $data['title'], 'definition' => json_encode($definition), 'status' => 'draft', 'updated_at' => now()]);
                $key = $id;
            } else $key = DB::table('admin_quiz_drafts')->insertGetId(['title' => $data['title'], 'definition' => json_encode($definition), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'save-draft', 'quizzes', (string) $key, ['definition']);
            return $key;
        });
        return response()->json(['data' => ['id' => $key]], $id ? 200 : 201);
    }
    public function publish(Request $request, string $id)
    {
        $version = DB::transaction(function () use ($request, $id) {
            $draft = DB::table('admin_quiz_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($draft->status === 'draft', 409, 'Only drafts can be published.');
            $definition = json_decode($draft->definition, true);
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
            if ($draft->quiz_id) Quiz::whereKey($draft->quiz_id)->update(['is_active' => false]);
            DB::table('admin_quiz_drafts')->where('id', $id)->update(['status' => 'archived', 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'archive', 'quizzes', $id);
        });
        return response()->json(['message' => 'Archived.']);
    }
}
