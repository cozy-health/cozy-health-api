@extends('emails.layout')
@section('title', 'Welcome to Cozy')
@section('content')
<p style="font-size:15px;line-height:1.7;overflow-wrap:anywhere">Hello {{ $providerName }},</p>
<p style="font-size:15px;line-height:1.7">Your provider account is ready. You can sign in to your dashboard even if you do not have any patients yet.</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0460D8" style="border-radius:12px;mso-padding-alt:14px 20px"><a href="{{ $portalUrl }}" style="display:inline-block;padding:14px 20px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none">Open provider dashboard</a></td></tr></table>
<h2 style="font-size:18px;margin-top:28px">Add your first patient</h2>
<ol style="padding-left:22px;font-size:14px;line-height:1.8"><li>A patient sends you a connection invitation through Cozy.</li><li>Sign in using the email address that received the invitation.</li><li>Choose <strong>Accept patient invitation</strong>, enter the emailed code, review their sharing choices, and select <strong>Agree &amp; connect</strong>.</li></ol>
<p style="font-size:14px;line-height:1.7;color:#595959">Patients control what they share and can change or revoke access at any time. Creating an account does not certify professional credentials.</p>
<p style="font-size:12px;line-height:1.7;color:#595959">If the button does not open, copy this address into your browser:<br><a href="{{ $portalUrl }}" style="color:#0460D8;overflow-wrap:anywhere">{{ $portalUrl }}</a></p>
@endsection
