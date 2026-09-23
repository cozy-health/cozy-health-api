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
        <h2>Hi <?php echo e($user->name); ?>,</h2>
        <p>You recently requested to reset your password for your Cozy Health account.</p>
        <p>Use the following 6-digit code to reset your password:</p>
        
        <div class="code-box">
            <?php echo e($token); ?>

        </div>
        
        <p><strong>Note:</strong> This code expires in 15 minutes.</p>
        
        <p>If you didn't request a password reset, you can safely ignore this email.</p>
        
        <div class="footer">
            <p>&copy; <?php echo e(date('Y')); ?> Cozy Health. All rights reserved.</p>
            <p>If you have any questions, contact our support team at team@cozyhealth.org.</p>
        </div>
    </div>
</body>
</html>
<?php /**PATH C:\projects\cozyhealth\cozy-health-api-main\resources\views/emails/password-reset-request.blade.php ENDPATH**/ ?>