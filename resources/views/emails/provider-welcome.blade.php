<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Welcome to Cozy Health</title></head>
<body style="margin:0;background:#ffffff;color:#171717;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;border:1px solid #e5e5e5;border-radius:20px"><tr><td style="padding:28px 24px">
<img src="{{ $previewLogo ?? $message->embed(public_path('images/cozy-logo.png')) }}" width="48" height="48" alt="Cozy Health" style="display:block;border:0">
<p style="font-weight:bold;font-size:18px">Cozy Health</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td width="25%" height="4" bgcolor="#0460D8"></td><td width="25%" bgcolor="#00BC4C"></td><td width="25%" bgcolor="#F25C05"></td><td width="25%" bgcolor="#F2CB05"></td></tr></table>
<h1 style="font-size:26px;line-height:1.3">Welcome to Cozy</h1>
<p style="font-size:15px;line-height:1.7;overflow-wrap:anywhere">Hello {{ $providerName }},</p>
<p style="font-size:15px;line-height:1.7">Your provider account is ready. You can sign in to your dashboard even if you do not have any patients yet.</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0460D8" style="border-radius:12px;mso-padding-alt:14px 20px"><a href="{{ $portalUrl }}" style="display:inline-block;padding:14px 20px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none">Open provider dashboard</a></td></tr></table>
<h2 style="font-size:18px;margin-top:28px">Add your first patient</h2>
<ol style="padding-left:22px;font-size:14px;line-height:1.8"><li>A patient sends you a connection invitation through Cozy.</li><li>Sign in using the email address that received the invitation.</li><li>Choose <strong>Add patient</strong>, enter the emailed code, review their sharing choices, and select <strong>Agree &amp; connect</strong>.</li></ol>
<p style="font-size:14px;line-height:1.7;color:#595959">Patients control what they share and can change or revoke access at any time. Creating an account does not certify professional credentials.</p>
<p style="font-size:12px;line-height:1.7;color:#595959">If the button does not open, copy this address into your browser:<br><a href="{{ $portalUrl }}" style="color:#0460D8;overflow-wrap:anywhere">{{ $portalUrl }}</a></p>
<p style="font-size:14px;line-height:1.7">Take care,<br>The Cozy Health team</p>
</td></tr></table></td></tr></table>
</body></html>
