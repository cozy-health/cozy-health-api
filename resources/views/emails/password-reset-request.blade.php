<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .code-box { background: #f4f4f4; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; margin: 20px 0; border-radius: 8px; }
        .footer { font-size: 12px; color: #777; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Hi {{ $user->name }},</h2>
        <p>You recently requested to reset your password for your Cozy Health account.</p>
        <p>Use the following 6-digit code to reset your password:</p>
        
        <div class="code-box">
            {{ $token }}
        </div>
        
        <p><strong>Note:</strong> This code expires in 15 minutes.</p>
        
        <p>If you didn't request a password reset, you can safely ignore this email.</p>
        
        <div class="footer">
            <p>This is an automated message — please do not reply to this email.</p>
            <p>For support, contact us at <a href="mailto:team@cozyhealth.org">team@cozyhealth.org</a>.</p>
            <p>&copy; {{ date('Y') }} Cozy Health. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
