@extends('emails.layout')
@section('title', 'Verify your email')
@section('content')
<p>Use this code to verify your Cozy Health email:</p>
@include('emails.partials.code', ['value' => $code])
<p>It expires in 15 minutes. If you didn't request it, you can ignore this email.</p>
@endsection
