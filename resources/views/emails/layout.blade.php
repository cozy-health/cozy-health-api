<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title', 'Cozy Health')</title>
</head>
<body style="margin:0;padding:0;background:#F4F7FC;color:#172B45;font-family:'Trebuchet MS',Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F4F7FC"><tr><td align="center" style="padding:32px 12px">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="max-width:600px;border:1px solid #E1E8F2;border-radius:20px;background:#FFFFFF">
<tr><td style="padding:28px 24px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="60" valign="middle"><img src="{{ $previewLogo ?? (isset($message) ? $message->embed(public_path('images/cozy-logo.png')) : 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/cozy-logo.png')))) }}" width="48" height="48" alt="Cozy Health" style="display:block;border:0;width:48px;height:48px"></td>
<td valign="middle" style="font-size:22px;line-height:1.3;font-weight:bold;color:#0460D8">Cozy Health</td>
</tr></table>
</td></tr>
<tr><td style="padding:0 24px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="25%" height="5" bgcolor="#0460D8" style="font-size:0;line-height:0">&nbsp;</td>
<td width="25%" height="5" bgcolor="#00BC4C" style="font-size:0;line-height:0">&nbsp;</td>
<td width="25%" height="5" bgcolor="#F25C05" style="font-size:0;line-height:0">&nbsp;</td>
<td width="25%" height="5" bgcolor="#F2CB05" style="font-size:0;line-height:0">&nbsp;</td>
</tr></table>
</td></tr>
<tr><td style="padding:28px 24px 24px;overflow-wrap:anywhere;word-break:break-word">
<h1 style="margin:0 0 20px;font-size:26px;line-height:1.3;color:#172B45">@yield('title')</h1>
<div style="font-size:16px;line-height:1.7;color:#31435B">@yield('content')</div>
</td></tr>
<tr><td style="padding:22px 24px;border-top:1px solid #E1E8F2;font-size:13px;line-height:1.7;color:#53647A">
Take care,<br><strong style="color:#172B45">The Cozy Health team</strong><br>
<a href="mailto:team@cozyhealth.org" style="color:#0460D8;text-decoration:underline">team@cozyhealth.org</a>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body></html>
