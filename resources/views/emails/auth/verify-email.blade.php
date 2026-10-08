@extends('emails.layout')
@section('title', 'Verify your email')
@section('content')
<p>Use this code to verify your Cozy Health email:</p>
<p style="font-size:28px;letter-spacing:6px;font-weight:bold">{{ $code }}</p>
<p>It expires in 15 minutes. If you didn't request it, you can ignore this email.</p>
@endsection
