@extends('emails.layout')
@section('title', 'Reset your password')
@section('content')
<p>Use this code to reset your Cozy Health password:</p>
<p style="font-size:28px;letter-spacing:6px;font-weight:bold">{{ $token }}</p>
<p>The code expires in 15 minutes. If you didn't request this, you can ignore this email.</p>
@endsection
