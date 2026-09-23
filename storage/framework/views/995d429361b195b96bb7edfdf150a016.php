<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .success-box { background: #e8f5e9; padding: 15px; color: #2e7d32; margin: 20px 0; border-radius: 8px; border-left: 4px solid #2e7d32; }
        .footer { font-size: 12px; color: #777; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Hi <?php echo e($user->name); ?>,</h2>
        
        <div class="success-box">
            Your password was successfully reset on <?php echo e(now()->format('F j, Y, g:i a')); ?>.
        </div>
        
        <p>You can now log in to your Cozy Health account using your new password.</p>
        <p>If you did not make this change, please contact us immediately to secure your account.</p>
        
        <div class="footer">
            <p>&copy; <?php echo e(date('Y')); ?> Cozy Health. All rights reserved.</p>
            <p>If you have any questions or need to report suspicious activity, contact our support team at team@cozyhealth.org.</p>
        </div>
    </div>
</body>
</html>
<?php /**PATH C:\projects\cozyhealth\cozy-health-api-main\resources\views/emails/password-reset-confirmation.blade.php ENDPATH**/ ?>