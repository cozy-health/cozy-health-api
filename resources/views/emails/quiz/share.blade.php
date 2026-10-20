@extends('emails.layout')
@section('title', 'An assessment was shared with you')
@section('content')
<p>A Cozy Health user has chosen to share an assessment with you.</p>
<p><a href="{{ $reviewUrl }}" style="color:#456f64">View the shared assessment securely</a></p>
<p>This email contains no assessment answers or scores.</p>
@endsection
