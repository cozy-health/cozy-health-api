You have a Cozy patient invitation

@if($providerName)Hello {!! $providerName !!},@else Hello,@endif

{!! $patientName !!} has invited you to connect as their provider on Cozy.

Connection code: {{ $code }}

@if($expiresAt)Expires {{ $expiresAt }}.
@endif
Codes are valid for 15 minutes after the invitation is created. Use only the latest code.

Open provider dashboard: {!! $portalUrl !!}

How to connect:
1. Sign in or create your provider account using {!! $recipientEmail ?: 'the email address receiving this invitation' !!}.
2. Choose Accept patient invitation and enter the connection code above.
3. Review the patient's sharing choices, then select Agree & connect.

The patient controls which information is shared and can change or revoke access at any time.
No wellness information is shared by this email.

If you did not expect this invitation, you can ignore this email.

Take care,
The Cozy Health team
