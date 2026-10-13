<?php

namespace App\Services;

use App\Models\{Quiz, QuizAttempt, QuizDefinition, User};
use App\Services\Analytics\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, RateLimiter, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentService
{
    public function enabled(): void
    {
        abort_unless(\App\Models\FeatureFlag::where('key', 'quiz')->value('enabled') ?? AppConfigService::DEFAULT_FLAGS['quiz'], 503, 'Assessments are temporarily paused. Your answers remain saved.');
    }

    public function catalog()
    {
        return Quiz::where('is_active', true)->whereNotNull('approved_at')->whereNotNull('scoring_rules')
            ->whereNotNull('disclaimer')->where('disclaimer', '!=', '');
    }

    public function publish(Quiz $quiz): QuizDefinition
    {
        // Database defaults and relation fields must hash identically on publication and later reads.
        $quiz = $quiz->fresh('questions.options');
        $this->catalog()->findOrFail($quiz->id);
        $definition = $quiz->toArray();
        unset($definition['created_at'], $definition['updated_at']);
        $definition['timeframe'] = $quiz->scoring_rules['timeframe'] ?? '';
        $this->validateDefinition($definition);
        $fingerprint = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
        $version = QuizDefinition::firstOrCreate(['quiz_id' => $quiz->id, 'fingerprint' => $fingerprint],
            ['id' => (string) Str::uuid(), 'definition' => $definition, 'approved_at' => $quiz->approved_at]);
        abort_if($version->revoked_at, 410, 'This definition was revoked. Publish a new approved version.');
        return $version;
    }

    public function validateDefinition(array $definition): void
    {
        $questions = $definition['questions'] ?? [];
        $expected = ['phq-9' => 9, 'gad-7' => 7, 'pss-10' => 10, 'sleep' => 8][$definition['slug'] ?? ''] ?? null;
        abort_if($expected !== null && count($questions) !== $expected, 503, 'Assessment question count is incorrect.');
        abort_if($expected !== null && trim($definition['timeframe'] ?? $definition['scoring_rules']['timeframe'] ?? '') === '', 503, 'Assessment timeframe is missing.');
        abort_unless(count($questions) > 0 && count($questions) <= 100, 503, 'Assessment questions are not configured.');
        $maximum = 0;
        foreach ($questions as $question) {
            $options = $question['options'] ?? [];
            abort_unless(count($options) > 1 && count($options) <= 10 && trim($question['question'] ?? '') !== '', 503, 'Assessment options are not configured.');
            foreach ($options as $option) {
                abort_unless(isset($option['id'], $option['score']) && is_numeric($option['score']) && $option['score'] >= 0 && trim($option['label'] ?? '') !== '', 503, 'Assessment option is invalid.');
            }
            $maximum += max(array_column($options, 'score'));
        }
        $bands = $definition['scoring_rules']['bands'] ?? [];
        $previous = -1;
        foreach ($bands as $band) {
            abort_unless(isset($band['max'], $band['label']) && $band['max'] > $previous && is_string($band['label']) && strlen($band['label']) <= 50, 503, 'Assessment scoring is invalid.');
            $previous = $band['max'];
        }
        abort_unless($previous >= $maximum && trim($definition['disclaimer'] ?? '') !== '', 503, 'Assessment scoring or disclaimer is incomplete.');
    }

    public function score(array $definition, array $answers): array
    {
        $this->validateDefinition($definition);
        if (count($answers) !== count($definition['questions'])) {
            throw ValidationException::withMessages(['answers' => 'Answer every question once.']);
        }
        $score = $maximum = 0;
        $positiveItem9 = false;
        foreach ($definition['questions'] as $index => $question) {
            $option = collect($question['options'])->first(fn ($option) => (int) $option['id'] === $answers[$index]);
            if (! $option) throw ValidationException::withMessages(['answers' => 'An answer is not an option for its question.']);
            $score += (int) $option['score'];
            $maximum += max(array_column($question['options'], 'score'));
            if ($index === 8 && $definition['slug'] === 'phq-9') $positiveItem9 = $option['score'] > 0;
        }
        $rules = $definition['scoring_rules'];
        $band = collect($rules['bands'])->first(fn ($band) => $score <= $band['max']);
        $crisis = $positiveItem9 || (in_array($definition['slug'], ['phq-9', 'gad-7']) && $score >= 15)
            || (isset($rules['crisis_threshold']) && $score >= $rules['crisis_threshold']);
        return ['score' => $score, 'maximum_score' => $maximum, 'interpretation' => $band['label'], 'is_crisis_flagged' => $crisis,
            'result_snapshot' => ['title' => $definition['title'], 'disclaimer' => $definition['disclaimer'],
                'recommendation' => $band['recommendation'] ?? $rules['recommendation'] ?? '',
                'definition' => $definition]];
    }

    public function submitLegacy(User $user, array $data): QuizAttempt
    {
        Validator::make($data, ['id' => 'required|uuid', 'answers' => 'required|array|list|max:100', 'answers.*' => 'required|integer'])->validate();
        $slug = $data['quiz_slug'] ?? '';
        if (! in_array($slug, ['phq-9', 'gad-7'], true)) throw ValidationException::withMessages(['definition_version' => 'This legacy assessment cannot be verified. Take a new approved assessment.']);
        $version = $this->publish($this->catalog()->where('slug', $slug)->firstOrFail());
        $count = $slug === 'phq-9' ? 9 : 7;
        $answers = $data['answers'] ?? [];
        if (count($answers) !== $count || count($version->definition['questions']) !== $count) throw ValidationException::withMessages(['answers' => 'Legacy assessment is incomplete.']);
        $mapped = [];
        foreach ($version->definition['questions'] as $index => $question) {
            $options = $question['options'];
            if (count($options) !== 4 || array_column($options, 'score') !== [0, 1, 2, 3] || ! is_int($answers[$index]) || $answers[$index] < 0 || $answers[$index] > 3) {
                throw ValidationException::withMessages(['answers' => 'Legacy answers do not match the approved definition.']);
            }
            $mapped[] = $options[$answers[$index]]['id'];
        }
        return $this->submit($user, [...$data, 'definition_version' => $version->id, 'answers' => $mapped, 'disclaimer_accepted' => true], null, true);
    }

    public function submit(User $user, array $data, ?array $snapshot = null, bool $legacy = false): QuizAttempt
    {
        $this->enabled();
        Validator::make($data, ['id' => 'required|uuid', 'answers' => 'required|array|list|min:1|max:100',
            'answers.*' => 'required|integer', 'completed_at' => 'sometimes|date|before_or_equal:now',
            'definition_version' => 'nullable|uuid', 'disclaimer_accepted' => 'required|accepted'])->validate();
        abort_if(RateLimiter::tooManyAttempts('quiz-submit:'.$user->id, 10), 429);
        RateLimiter::hit('quiz-submit:'.$user->id, 60);
        return DB::transaction(function () use ($user, $data, $snapshot, $legacy) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = QuizAttempt::withoutGlobalScope('user')->withTrashed()->whereKey($data['id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id, 404);
                abort_if($existing->trashed() || $existing->is_deleted, 410, 'This result was deleted.');
                if ($existing->validated_at) {
                    abort_unless($existing->answers === $data['answers'] && (! isset($data['definition_version']) || $existing->definition_version === $data['definition_version']), 409, 'This attempt has already been submitted.');
                    return $existing;
                }
                abort_unless($legacy, 409, 'Retake this legacy assessment.');
            }
            $version = isset($data['definition_version']) ? QuizDefinition::findOrFail($data['definition_version']) : null;
            $definition = $snapshot ?? $version?->definition;
            abort_unless($definition, 422, 'An approved definition version is required.');
            abort_if($version?->revoked_at, 410, 'This definition was revoked.');
            $this->catalog()->findOrFail($definition['id']);
            $version ??= $this->publish(Quiz::findOrFail($definition['id']));
            $computed = $this->score($definition, $data['answers']);
            $attributes = [...$computed, 'id' => $data['id'], 'user_id' => $user->id,
                'quiz_slug' => $definition['slug'], 'quiz_title' => $definition['title'], 'answers' => $data['answers'],
                'definition_version' => $version->id, 'completed_at' => isset($data['completed_at']) ? Carbon::parse($data['completed_at']) : now(),
                'client_created_at' => $existing?->client_created_at ?? now(), 'validated_at' => now(), 'disclaimer_accepted_at' => $legacy ? null : now()];
            if ($existing) { $existing->fill($attributes)->save(); $attempt = $existing; }
            else { $attempt = QuizAttempt::create($attributes); app(AnalyticsService::class)->track('quiz_completed'); }
            return $attempt;
        });
    }
}
