@extends('emails.layout')
@section('title', 'You have a patient invitation')
@section('content')
  <p style="margin:0 0 12px;font-size:15px;line-height:1.7">@if($providerName)Hello {{ $providerName }},@else Hello,@endif</p>
  <p style="margin:0 0 24px;font-size:15px;line-height:1.7;overflow-wrap:anywhere"><strong>{{ $patientName }}</strong> has invited you to connect as their provider on Cozy.</p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #0460D8;border-radius:14px"><tr><td align="center" style="padding:20px 12px">
    <p style="margin:0 0 10px;font-size:12px;line-height:1.5;color:#595959">YOUR CONNECTION CODE</p>
    <p style="margin:0;font-size:32px;font-weight:bold;line-height:1.4;letter-spacing:6px;color:#0460D8">{{ $code }}</p>
  </td></tr></table>
  <p style="margin:12px 0 24px;font-size:12px;line-height:1.7;color:#595959">@if($expiresAt)Expires {{ $expiresAt }}.@endif Codes are valid for 15 minutes after the invitation is created. Use only the latest code.</p>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px"><tr><td bgcolor="#0460D8" style="border-radius:12px;mso-padding-alt:14px 20px"><a href="{{ $portalUrl }}" style="display:inline-block;padding:14px 20px;font-size:14px;font-weight:bold;line-height:1.4;color:#ffffff;text-decoration:none">Open provider dashboard</a></td></tr></table>
  <h2 style="margin:0 0 12px;font-size:17px;line-height:1.5">How to connect</h2>
  <ol style="margin:0 0 24px;padding-left:22px;font-size:14px;line-height:1.8">
    <li>Sign in or create your provider account using <strong style="overflow-wrap:anywhere">{{ $recipientEmail ?: 'the email address receiving this invitation' }}</strong>.</li>
    <li>Choose <strong>Accept patient invitation</strong> and enter the connection code above.</li>
    <li>Review the patient's sharing choices, then select <strong>Agree &amp; connect</strong>.</li>
  </ol>
  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#595959">The patient controls which information is shared and can change or revoke access at any time. No wellness information is shared by this email.</p>
  <p style="margin:0 0 24px;font-size:12px;line-height:1.7;color:#595959">If the button does not open, copy this address into your browser:<br><a href="{{ $portalUrl }}" style="color:#0460D8;overflow-wrap:anywhere">{{ $portalUrl }}</a></p>
<p style="font-size:12px;line-height:1.7;color:#595959">If you did not expect this invitation, you can ignore this email.</p>
@endsection
