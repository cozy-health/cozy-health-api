@extends('emails.layout')
@section('title'){{ $heading }}@endsection
@section('content')
@foreach($paragraphs as $paragraph)
<p style="margin:0 0 18px">{{ $paragraph }}</p>
@endforeach
@foreach($actions as $action)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0"><tr><td bgcolor="#0460D8" style="border-radius:12px;mso-padding-alt:14px 20px"><a href="{{ $action['url'] }}" style="display:inline-block;padding:14px 20px;color:#FFFFFF;font-size:15px;line-height:1.5;font-weight:bold;text-decoration:none">{{ $action['label'] }}</a></td></tr></table>
<p style="margin:0 0 20px;font-size:12px;line-height:1.7;overflow-wrap:anywhere">If the button does not open, copy this address into your browser:<br><a href="{{ $action['url'] }}" style="color:#0460D8">{{ $action['url'] }}</a></p>
@endforeach
@endsection
