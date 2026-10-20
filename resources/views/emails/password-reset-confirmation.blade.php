<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">

    <title>Password Reset Successful - Cozy Health</title>

    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                Helvetica, Arial, sans-serif;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
        }

        table { border-collapse: collapse; }
        img { border: 0; display: block; max-width: 100%; }
        a { text-decoration: none; }

        .email-wrapper {
            width: 100%;
            padding: 40px 16px;
            background-color: #f8fafc;
        }

        .email-container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(4, 96, 216, 0.05);
        }

        /* Header */
        .header {
            padding: 40px;
            background-color: #0460d8;
            background: linear-gradient(135deg, #0460d8 0%, #034eb0 100%);
        }

        .brand {
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.2px;
            margin-bottom: 32px;
        }

        .header-label {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            color: #ffffff;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .header-title {
            margin: 0 0 12px 0;
            color: #ffffff;
            font-size: 30px;
            line-height: 1.25;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* Content */
        .content {
            padding: 40px;
        }

        /* Footer */
        .footer {
            padding: 24px 32px;
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }

        @media only screen and (max-width: 600px) {
            .email-wrapper { padding: 16px; }
            .header { padding: 32px 24px; }
            .header-title { font-size: 26px; }
            .content { padding: 32px 24px; }
            .footer { padding: 24px; }
        }
    </style>
</head>

<body style="margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8fafc;">
        <tr>
            <td class="email-wrapper" style="padding: 40px 16px;">
                <table role="presentation" class="email-container" cellpadding="0" cellspacing="0" border="0"
                    align="center" width="100%"
                    style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 8px 24px rgba(4, 96, 216, 0.05); overflow: hidden;">
                    
                    <!-- HEADER -->
                    <tr>
                        <td class="header" style="padding: 40px; background-color: #0460d8; background: linear-gradient(135deg, #0460d8 0%, #034eb0 100%);">
                            <div class="brand" style="margin-bottom: 32px;">
                                <span style="display: inline-block; vertical-align: middle; background: #ffffff; border-radius: 10px; padding: 5px; line-height: 0;">
                                    <img src="https://www.cozyhealth.org/logo.png" alt="Cozy Health" width="34" height="34" style="display: block; border: 0;" />
                                </span>
                                <span style="display: inline-block; vertical-align: middle; margin-left: 10px; color: #ffffff; font-size: 20px; font-weight: 700; letter-spacing: -0.2px;">Cozy Health</span>
                            </div>
                            <div class="header-label" style="display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.15); border-radius: 20px; color: #ffffff; font-size: 11px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 16px;">
                                ACCOUNT SECURITY
                            </div>
                            <h1 class="header-title" style="margin: 0; color: #ffffff; font-size: 30px; line-height: 1.25; font-weight: 700; letter-spacing: -0.5px;">
                                Password Reset Successful
                            </h1>
                        </td>
                    </tr>

                    <!-- CONTENT -->
                    <tr>
                        <td class="content" style="padding: 40px;">
                            <h2 style="margin: 0 0 16px 0; color: #0f172a; font-size: 24px; line-height: 1.3; font-weight: 700; letter-spacing: -0.3px;">
                                Hi {{ $user->name }},
                            </h2>
                            
                            <div style="background-color: #e6f8ec; border-left: 4px solid #00bc4c; border-radius: 0 8px 8px 0; padding: 16px; margin: 24px 0;">
                                <p style="margin: 0; color: #0f172a; font-size: 15px; font-weight: 500;">
                                    Your password was successfully reset on {{ now()->format('F j, Y, g:i a') }}.
                                </p>
                            </div>
                            
                            <p style="margin: 0 0 16px 0; color: #475569; font-size: 16px; line-height: 1.6;">
                                You can now log in to your Cozy Health account using your new password.
                            </p>
                            <p style="margin: 0 0 32px 0; color: #64748b; font-size: 14px; line-height: 1.6;">
                                If you did not make this change, please contact us immediately to secure your account.
                            </p>

                            <!-- CTA -->
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; text-align: center;">
                                <a href="https://dashboard.cozyhealth.org/login"
                                    style="display: inline-block; padding: 14px 28px; background-color: #0460d8; color: #ffffff; border-radius: 8px; font-size: 15px; font-weight: 600; letter-spacing: 0.2px; text-decoration: none;">
                                    Log In to Cozy Health
                                </a>
                                <p style="margin: 16px 0 0 0; color: #94a3b8; font-size: 13px;">
                                    Suspicious activity? Please reach out to our <a href="mailto:support@cozyhealth.org" style="color: #0460d8; text-decoration: underline;">support team</a>.
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- FOOTER -->
                    <tr>
                        <td class="footer" style="padding: 24px 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #64748b; line-height: 1.6;">
                            <p style="margin: 0 0 16px 0;">
                                Please contact <a href="mailto:support@cozyhealth.org" style="color: #0460d8; text-decoration: underline;">customer service</a> if you have any questions.
                            </p>
                            <p style="margin: 0 0 16px 0;">
                                <a href="https://cozyhealth.org/privacy" style="color: #64748b; text-decoration: underline;">Privacy & Cookie Policy</a>
                                &nbsp;|&nbsp;
                                <a href="https://cozyhealth.org/terms" style="color: #64748b; text-decoration: underline;">Terms & Conditions</a>
                                &nbsp;|&nbsp;
                                <a href="#" style="color: #64748b; text-decoration: underline;">Unsubscribe</a>
                            </p>
                            <p style="margin: 0 0 8px 0; color: #94a3b8; font-size: 11px;">
                                This is an automated message. Please do not reply directly to this email unless directed otherwise.
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #94a3b8;">
                                &copy; {{ date('Y') }} Cozy Health. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>