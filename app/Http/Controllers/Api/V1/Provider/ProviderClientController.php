<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\ProviderAccessLog;
use App\Models\QuizAttempt;
use App\Services\Provider\ProviderAudit;
use App\Services\Provider\ProviderClientAccess;
use App\Services\Provider\ProviderMoodInsights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderClientController extends Controller
{
    public function index(Request $request, ProviderMoodInsights $insights)
    {
        return DB::transaction(function () use ($request, $insights) {
            $links = app(\App\Services\Provider\ProviderOverviewData::class)->links($request->user());
            $summaries = $insights->rosterSummaries($links);
            $clients = $links->map(function ($link) use ($request, $summaries) {
                ProviderAudit::record($request, $link, 'list', 'client', (string) $link->user_id);

                return $this->client($link, $link->consent_moods ? ($summaries[$link->user_id] ?? null) : null);
            });
            if ($links->isEmpty()) {
                ProviderAccessLog::create(['provider_id' => $request->user()->id, 'action' => 'list', 'resource_type' => 'client',
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            }

            return response()->json($clients)->header('Cache-Control', 'private, no-store');
        });
    }

    public function show(Request $request, string $userId)
    {
        return DB::transaction(function () use ($request, $userId) {
            $link = ProviderClientAccess::link($request, $userId);
            $client = [...$this->client($link), 'gender' => $link->user->gender,
                'date_of_birth' => $link->user->date_of_birth?->format('Y-m-d')];
            ProviderAudit::record($request, $link, 'view', 'client', $userId);

            return response()->json($client);
        });
    }

    public function consents(Request $request, string $userId)
    {
        return DB::transaction(function () use ($request, $userId) {
            $link = ProviderClientAccess::link($request, $userId);
            ProviderAudit::record($request, $link, 'view', 'consent', (string) $link->id);

            return response()->json(['consent_flags' => $link->consentFlags(), 'linked_at' => $link->linked_at]);
        });
    }

    public function moods(Request $request, string $userId)
    {
        return $this->entries($request, $userId, 'moods');
    }

    public function insights(Request $request, string $userId, ProviderMoodInsights $insights)
    {
        $validated = $request->validate(['period' => ['required', 'in:week,month,year']]);
        return DB::transaction(function () use ($request, $userId, $insights, $validated) {
            $link = ProviderClientAccess::link($request, $userId, 'moods');
            $result = $insights->patientInsights($link, $validated['period']);
            ProviderAudit::record($request, $link, 'view', 'mood_insight', $validated['period']);

            return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
        });
    }

    public function journals(Request $request, string $userId)
    {
        return $this->entries($request, $userId, 'journals');
    }

    public function quizzes(Request $request, string $userId)
    {
        return $this->entries($request, $userId, 'quizzes');
    }

    private function entries(Request $request, string $userId, string $type)
    {
        return DB::transaction(function () use ($request, $userId, $type) {
            $link = ProviderClientAccess::link($request, $userId, $type);
            $perPage = ProviderClientAccess::pagination($request);
            $query = match ($type) {
                'moods' => MoodEntry::withoutGlobalScope('user')->select(['id', 'mood', 'intensity', 'body_sensations',
                    'triggers', 'custom_trigger', 'sleep_quality', 'energy_level', 'note', 'coping_strategies',
                    'coping_helped', 'client_created_at', 'created_at']),
                // Never fetch journal body/transcription/prompt_text/voice_url into the result.
                'journals' => JournalEntry::withoutGlobalScope('user')->where('is_draft', false)
                    ->select(['id', 'title', 'type', 'word_count', 'created_at'])
                    ->selectRaw("CASE WHEN voice_url IS NOT NULL AND voice_url <> '' THEN 1 ELSE 0 END AS has_voice"),
                'quizzes' => QuizAttempt::withoutGlobalScope('user')->select(['id', 'quiz_slug', 'score', 'interpretation', 'completed_at']),
            };
            if ($type === 'quizzes') app(\App\Services\QuizSharingService::class)->filterProvider($query, $link);
            $query->where('user_id', $link->user_id)->where('is_deleted', false);
            $timestamp = $type === 'quizzes' ? 'completed_at' : DB::raw('COALESCE(client_created_at, created_at)');
            if ($type === 'moods' && ($request->filled('from') || $request->filled('to'))) {
                $from = (string) $request->input('from', $request->input('to'));
                $to = (string) $request->input('to', $request->input('from'));
                [$start, $end] = app(ProviderMoodInsights::class)->utcBoundsForDates($link->user->timezone, $from, $to);
                $query->whereBetween($timestamp, [$start, $end]);
            } else {
                if ($request->filled('from')) $query->whereDate($timestamp, '>=', $request->input('from'));
                if ($request->filled('to')) $query->whereDate($timestamp, '<=', $request->input('to'));
            }
            $entries = $query->orderByDesc($timestamp)->orderByDesc('id')->paginate($perPage);
            if ($type === 'journals') {
                $entries->through(function ($entry) {
                    $entry->has_voice = (bool) $entry->has_voice;

                    return $entry;
                });
            }
            ProviderAudit::record($request, $link, 'list', match ($type) {
                'moods' => 'mood', 'journals' => 'journal', 'quizzes' => 'quiz'
            });

            return response()->json($entries);
        });
    }

    private function client($link, ?array $moodSummary = null): array
    {
        return ['user_id' => $link->user_id, 'name' => preg_split('/\s+/u', trim($link->user->name))[0],
            'linked_at' => $link->linked_at, 'consent_flags' => $link->consentFlags(), 'mood_summary' => $moodSummary];
    }
}
