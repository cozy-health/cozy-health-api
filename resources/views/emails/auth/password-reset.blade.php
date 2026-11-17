@extends('emails.layout')
@section('title', 'Reset your password')
@section('content')
<p>Use this code to reset your Cozy Health password:</p>
@include('emails.partials.code', ['value' => $token])
<p>The code expires in 15 minutes. If you didn't request this, you can ignore this email.</p>
@endsection
