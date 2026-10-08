<?php

namespace App\Services\Provider;

use App\Models\JournalEntry;
use App\Models\MoodEntry;
use App\Models\Provider;
use App\Models\ProviderClientLink;
use App\Models\ProviderNote;
use App\Models\ProviderReport;
use App\Models\QuizAttempt;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProviderReportService
{
    public function generate(Provider $provider, User $user, string $type = 'monthly', ?Carbon $from = null, ?Carbon $to = null): ProviderReport
    {
        $timezone = $provider->timezone ?: 'UTC';
        $from = ($from ?? Carbon::now($timezone)->startOfMonth())->copy()->setTimezone($timezone)->startOfDay();
        $to = ($to ?? Carbon::now($timezone))->copy()->setTimezone($timezone)->endOfDay();
        if ($type !== 'monthly' || $from->gt($to) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose a monthly report period of at most one year, with from before to.']);
        }
        $path = null;
        try {
            return DB::transaction(function () use ($provider, $user, $type, $from, $to, &$path) {
                abort_unless($provider->is_active, 404);
                $link = ProviderClientLink::active()->where('provider_id', $provider->id)->where('user_id', $user->id)->whereHas('user')->lockForUpdate()->firstOrFail();
                $flags = $link->consentFlags();
                $start = $from->copy()->utc();
                $end = $to->copy()->utc();
                $summary = ['moods' => null, 'journals' => null, 'quizzes' => null, 'notes' => null];
                if ($flags['moods']) {
                    $moods = MoodEntry::withoutGlobalScope('user')->where('user_id', $user->id)->where('is_deleted', false)
                        ->whereBetween(DB::raw('COALESCE(client_created_at, created_at)'), [$start, $end])
                        ->get(['mood', 'intensity', 'triggers', 'custom_trigger']);
                    $triggers = [];
                    foreach ($moods as $mood) {
                        $labels = collect([...(is_array($mood->triggers) ? $mood->triggers : []), $mood->custom_trigger])
                            ->filter(fn ($label) => is_string($label) && trim($label) !== '')->map(fn ($label) => trim($label))->unique();
                        foreach ($labels as $label) {
                            $triggers[$label] = ($triggers[$label] ?? 0) + 1;
                        }
                    }
                    arsort($triggers);
                    $summary['moods'] = ['count' => $moods->count(), 'average_intensity' => $moods->isEmpty() ? null : round($moods->avg('intensity'), 1),
                        'by_mood' => $moods->countBy('mood')->all(), 'triggers' => array_slice($triggers, 0, 5, true)];
                }
                if ($flags['journals']) {
                    // SQL aggregation never retrieves titles, bodies, transcripts, prompts or recordings.
                    $types = JournalEntry::withoutGlobalScope('user')->where('user_id', $user->id)->where('is_deleted', false)->where('is_draft', false)
                        ->whereBetween(DB::raw('COALESCE(client_created_at, created_at)'), [$start, $end])
                        ->select('type')->selectRaw('COUNT(*) AS total')->groupBy('type')->pluck('total', 'type')->all();
                    $summary['journals'] = ['count' => array_sum($types), 'types' => $types];
                }
                if ($flags['quizzes']) {
                    $summary['quizzes'] = QuizAttempt::withoutGlobalScope('user')->where('user_id', $user->id)->where('is_deleted', false)
                        ->whereBetween('completed_at', [$start, $end])->orderBy('completed_at')->get(['quiz_slug', 'score', 'interpretation', 'completed_at']);
                }
                if ($flags['notes']) {
                    $summary['notes'] = ProviderNote::where('provider_id', $provider->id)->where('user_id', $user->id)
                        ->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get(['body', 'created_at']);
                }
                $generatedAt = now();
                $pdf = Pdf::loadView('provider.reports.monthly', ['provider' => $provider, 'patientName' => preg_split('/\s+/u', trim($user->name))[0],
                    'from' => $from, 'to' => $to, 'summary' => $summary, 'generatedAt' => $generatedAt])
                    ->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]);
                $path = 'provider-reports/'.$provider->id.'/'.Str::uuid().'.pdf';
                if (! Storage::disk('local')->put($path, $pdf->output())) {
                    throw new \RuntimeException('Unable to save provider report.');
                }
                $report = ProviderReport::create(['provider_id' => $provider->id, 'user_id' => $user->id, 'type' => $type,
                    'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'file_path' => $path,
                    'generated_at' => $generatedAt, 'link_id' => $link->id, 'link_verified_at' => $link->verified_at, 'consent_snapshot' => $flags]);
                ProviderAudit::record(request(), $link, 'create', 'report', (string) $report->id);

                return $report;
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function accessible(ProviderReport $report, ProviderClientLink $link): bool
    {
        if ($report->link_id !== $link->id || ! $report->link_verified_at?->equalTo($link->verified_at) || ! is_array($report->consent_snapshot)) {
            return false;
        }
        foreach (['moods', 'journals', 'quizzes', 'notes'] as $type) {
            if (! array_key_exists($type, $report->consent_snapshot) || ($report->consent_snapshot[$type] && ! $link->{'consent_'.$type})) {
                return false;
            }
        }

        return true;
    }
}
