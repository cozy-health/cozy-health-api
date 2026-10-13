<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Cozy Health assessment result</title></head><body>
<h1>{{ $result['quiz_title'] }}</h1>
<p>Completed: {{ $result['completed_at'] }}</p>
<p>Score: {{ $result['score'] }} / {{ $result['maximum_score'] }}</p>
<p>{{ $result['interpretation'] }}</p><p>{{ $result['recommendation'] }}</p>
<p>{{ $result['disclaimer'] }}</p><p>Not a substitute for professional care. This is not a diagnosis.</p>
</body></html>
