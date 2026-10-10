<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PaginationRequest;
use App\Models\ProviderClientLink;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Services\Analytics\AnalyticsService;
use App\Services\Provider\ProviderAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuizCatalogController extends ApiController
{
    private function catalog()
    {
        return Quiz::where('is_active', true)->whereNotNull('approved_at')->whereNotNull('scoring_rules')
            ->whereNotNull('disclaimer')->where('disclaimer', '!=', '');
    }

    public function index(PaginationRequest $request)
    {
        return $this->success($this->catalog()->orderBy('sort_order')->orderBy('id')->paginate($request->input('per_page', 20)));
    }

    public function show(string $slug)
    {
        return $this->success($this->catalog()->where('slug', $slug)->with('questions.options')->firstOrFail());
    }

    public function start(Request $request, string $id)
    {
        $request->validate(['disclaimer_accepted' => ['required', 'accepted']]);
        $quiz = $this->catalog()->with('questions.options')->findOrFail($id);
        abort_if($quiz->questions->isEmpty() || $quiz->questions->contains(fn ($q) => $q->options->isEmpty()), 503, 'This assessment is not ready.');
        // Snapshot approved questions/scoring so an in-progress assessment
        // cannot change semantics when an editor changes the catalog.
        $session = QuizSession::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id,
            'quiz_id' => $quiz->id, 'definition' => $quiz->toArray(), 'expires_at' => now()->addDays(7)]);

        return $this->success(['attempt_id' => $session->id, 'expires_at' => $session->expires_at, 'quiz' => $quiz]);
    }

    public function submit(Request $request, string $id)
    {
        $data = $request->validate(['attempt_id' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'list', 'min:1', 'max:100'], 'answers.*' => ['required', 'integer']]);

        return DB::transaction(function () use ($request, $id, $data) {
            $session = QuizSession::where('user_id', $request->user()->id)->where('quiz_id', $id)
                ->whereKey($data['attempt_id'])->lockForUpdate()->firstOrFail();
            $existing = QuizAttempt::withTrashed()->where('user_id', $request->user()->id)->find($session->id);
            if ($existing) {
                abort_if($existing->trashed() || $existing->is_deleted, 410, 'This assessment result was deleted. Start a new assessment.');
                abort_unless($existing->answers === array_map('intval', $data['answers']), 409, 'This assessment has already been submitted.');

                return $this->success($existing);
            }
            abort_if($session->expires_at->isPast(), 410, 'This assessment session has expired.');
            $quiz = $session->definition;
            // Revoking approval also disables submissions of old snapshots.
            $this->catalog()->findOrFail($id);
            if (count($data['answers']) !== count($quiz['questions'])) {
                throw ValidationException::withMessages(['answers' => ['Answer every question once.']]);
            }
            $score = 0;
            foreach ($quiz['questions'] as $index => $question) {
                $option = collect($question['options'])->first(fn ($option) => (int) $option['id'] === (int) $data['answers'][$index]);
                if (! $option) {
                    throw ValidationException::withMessages(['answers' => ['An answer is not an option for its question.']]);
                }
                $score += (int) $option['score'];
            }
            $rules = $quiz['scoring_rules'];
            $band = collect($rules['bands'] ?? [])->first(fn ($band) => isset($band['max'], $band['label']) && $score <= $band['max']);
            abort_unless($band && is_string($band['label']) && strlen($band['label']) <= 50, 503, 'Assessment scoring is not configured.');
            $crisis = isset($rules['crisis_threshold']) && $score >= $rules['crisis_threshold'];
            // Safety thresholds required by the build plan are not overrideable
            // by a client-supplied score or interpretation.
            if (in_array($quiz['slug'], ['phq-9', 'gad-7'], true) && $score >= 15) {
                $crisis = true;
            }
            $attempt = QuizAttempt::create(['id' => $session->id, 'user_id' => $request->user()->id,
                'quiz_slug' => $quiz['slug'], 'quiz_title' => $quiz['title'], 'answers' => array_map('intval', $data['answers']),
                'score' => $score, 'interpretation' => $band['label'], 'is_crisis_flagged' => $crisis,
                'completed_at' => now(), 'client_created_at' => $session->created_at]);
            app(AnalyticsService::class)->track('quiz_completed');

            return $this->success($attempt);
        });
    }

    public function attempts(PaginationRequest $request)
    {
        return $this->success(QuizAttempt::where('user_id', $request->user()->id)->where('is_deleted', false)
            ->orderByDesc('completed_at')->orderBy('id')->paginate($request->input('per_page', 20)));
    }

    public function attempt(Request $request, string $id)
    {
        return $this->success(QuizAttempt::where('user_id', $request->user()->id)->where('is_deleted', false)->findOrFail($id));
    }

    public function share(Request $request, string $id)
    {
        $data = $request->validate(['provider_id' => ['required', 'integer'], 'consent' => ['required', 'accepted']]);

        return DB::transaction(function () use ($request, $id, $data) {
            $attempt = QuizAttempt::where('user_id', $request->user()->id)->where('is_deleted', false)->findOrFail($id);
            $link = ProviderClientLink::where('user_id', $request->user()->id)->where('provider_id', $data['provider_id'])
                ->active()->whereHas('provider', fn ($q) => $q->where('is_active', true)->where('is_verified', true))
                ->lockForUpdate()->firstOrFail();
            abort_unless($link->consent_quizzes, 403, 'Enable quiz access for this provider before sharing.');
            DB::table('quiz_shares')->updateOrInsert(['quiz_attempt_id' => $attempt->id, 'provider_id' => $link->provider_id],
                ['user_id' => $request->user()->id, 'link_id' => $link->id, 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            ProviderAudit::record($request, $link, 'shared', 'quiz', $attempt->id);

            return $this->success(['attempt_id' => $attempt->id, 'provider_id' => $link->provider_id, 'shared' => true]);
        });
    }
}
