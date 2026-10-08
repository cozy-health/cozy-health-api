<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Confidential provider report</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#253047}h1{font-size:20px}h2{font-size:14px;margin-top:22px}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left}footer{margin-top:28px;font-size:9px;color:#555}.note{white-space:pre-wrap;overflow-wrap:break-word}</style></head><body>
<h1>{{ $provider->name }}</h1><p>{{ $provider->practice }}</p>
<p>Patient: {{ $patientName }}<br>Report period: {{ $from->toDateString() }} to {{ $to->toDateString() }}</p>
@if($summary['moods'] !== null)
<h2>Mood summary</h2><p>Entries: {{ $summary['moods']['count'] }} · Average intensity: {{ $summary['moods']['average_intensity'] ?? 'No entries' }}</p>
<table><tr><th>Mood</th><th>Entries</th></tr>@foreach($summary['moods']['by_mood'] as $mood => $count)<tr><td>{{ $mood }}</td><td>{{ $count }}</td></tr>@endforeach</table>
<h2>Trigger frequency — top 5</h2><table>@forelse($summary['moods']['triggers'] as $trigger => $count)<tr><td>{{ $trigger }}</td><td>{{ $count }}</td></tr>@empty<tr><td>No triggers recorded.</td></tr>@endforelse</table>
@endif
@if($summary['journals'] !== null)
<h2>Journal metadata summary</h2><p>Entries: {{ $summary['journals']['count'] }}</p><table>@foreach($summary['journals']['types'] as $type => $count)<tr><td>{{ $type }}</td><td>{{ $count }}</td></tr>@endforeach</table>
@endif
@if($summary['quizzes'] !== null)
<h2>Quiz summary</h2><table><tr><th>Quiz</th><th>Score</th><th>Interpretation</th><th>Completed</th></tr>@forelse($summary['quizzes'] as $quiz)<tr><td>{{ $quiz->quiz_slug }}</td><td>{{ $quiz->score }}</td><td>{{ $quiz->interpretation }}</td><td>{{ $quiz->completed_at?->toDateString() }}</td></tr>@empty<tr><td colspan="4">No quiz results in this period.</td></tr>@endforelse</table>
@endif
@if($summary['notes'] !== null)
<h2>Provider notes summary</h2><p>Notes: {{ $summary['notes']->count() }}</p>@foreach($summary['notes'] as $note)<p>{{ $note->created_at->toDateString() }}</p><div class="note">{{ $note->body }}</div>@endforeach
@endif
<footer>Generated {{ $generatedAt->toIso8601String() }} · confidential</footer>
</body></html>
