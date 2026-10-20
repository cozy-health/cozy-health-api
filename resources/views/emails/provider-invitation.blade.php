<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cozy provider invitation</title></head>
<body style="margin:0;padding:0;background:#ffffff;color:#171717;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;border:1px solid #e5e5e5;border-radius:20px;background:#ffffff">
<tr><td style="padding:28px 24px 0">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
    <td style="width:56px"><img src="{{ $previewLogo ?? $message->embed(public_path('images/cozy-logo.png')) }}" width="48" height="48" alt="Cozy Health" style="display:block;border:0"></td>
    <td style="padding-left:12px"><strong style="font-size:18px;color:#171717">Cozy Health</strong><br><span style="font-size:12px;color:#595959">Provider connection invitation</span></td>
  </tr></table>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px"><tr>
    <td width="25%" height="4" style="background:#0460D8;font-size:0;line-height:0">&nbsp;</td><td width="25%" height="4" style="background:#00BC4C;font-size:0;line-height:0">&nbsp;</td><td width="25%" height="4" style="background:#F25C05;font-size:0;line-height:0">&nbsp;</td><td width="25%" height="4" style="background:#F2CB05;font-size:0;line-height:0">&nbsp;</td>
  </tr></table>
  <h1 style="margin:24px 0 16px;font-size:26px;line-height:1.3;color:#171717">You have a patient invitation</h1>
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
</td></tr>
<tr><td style="padding:20px 24px;border-top:1px solid #e5e5e5;font-size:12px;line-height:1.7;color:#595959">
  If you did not expect this invitation, you can ignore this email.<br>Take care,<br><strong style="color:#171717">The Cozy Health team</strong>
</td></tr>
</table></td></tr></table>
</body></html>
