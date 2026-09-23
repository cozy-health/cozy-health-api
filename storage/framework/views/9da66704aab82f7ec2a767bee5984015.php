<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Cozy Health</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-top: 40px;
            margin-bottom: 40px;
        }
        .header {
            background-color: #2b5c5d; /* Cozy Health Teal */
            padding: 40px 20px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 28px;
            letter-spacing: 1px;
        }
        .content {
            padding: 40px 30px;
            color: #333333;
            line-height: 1.6;
        }
        .content h2 {
            color: #2b5c5d;
            font-size: 22px;
            margin-top: 0;
        }
        .content p {
            margin-bottom: 20px;
            font-size: 16px;
        }
        .features {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin: 30px 0;
        }
        .feature-box {
            background-color: #f9fbfb;
            border-left: 4px solid #84b59f; /* Accent Light Green */
            padding: 15px 20px;
            border-radius: 4px;
        }
        .feature-title {
            font-weight: bold;
            color: #2b5c5d;
            margin-bottom: 5px;
            font-size: 16px;
        }
        .feature-desc {
            font-size: 14px;
            color: #555555;
            margin: 0;
        }
        .cta-container {
            text-align: center;
            margin: 40px 0 20px;
        }
        .cta-button {
            display: inline-block;
            background-color: #2b5c5d;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 32px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 2px 4px rgba(43, 92, 93, 0.2);
        }
        .footer {
            background-color: #f4f7f6;
            padding: 30px 20px;
            text-align: center;
            color: #777777;
            font-size: 13px;
            border-top: 1px solid #eeeeee;
        }
        .footer a {
            color: #2b5c5d;
            text-decoration: none;
        }
        
        @media only screen and (max-width: 600px) {
            .container {
                margin-top: 0;
                margin-bottom: 0;
                border-radius: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Cozy Health</h1>
        </div>

        <!-- Main Content -->
        <div class="content">
            <h2>Welcome <?php echo e($user->name); ?>!</h2>
            
            <p>Hi <?php echo e($user->name); ?>,</p>
            
            <p>Thank you for joining Cozy Health. We're incredibly excited to have you on board.</p>
            
            <p>Cozy Health is your personal mental health companion. We built this platform to help you track your mood, identify triggers, discover coping strategies, and connect seamlessly with healthcare providers.</p>

            <!-- Features -->
            <div class="features">
                <div class="feature-box">
                    <div class="feature-title">😊 Track Your Mood</div>
                    <p class="feature-desc">Log your feelings daily and see patterns over time.</p>
                </div>
                
                <div class="feature-box">
                    <div class="feature-title">🎯 Identify Triggers</div>
                    <p class="feature-desc">Understand what affects your mental health the most.</p>
                </div>
                
                <div class="feature-box">
                    <div class="feature-title">🤝 Connect with Providers</div>
                    <p class="feature-desc">Invite healthcare providers to guide your journey.</p>
                </div>
            </div>

            <!-- CTA -->
            <div class="cta-container">
                <a href="https://dashboard.cozyhealth.org" class="cta-button">Get Started</a>
                <p style="margin-top: 20px; font-size: 14px; color: #777;">Or simply reply to this email if you have questions.</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Questions? Contact our support team at <a href="mailto:support@cozyhealth.org">support@cozyhealth.org</a></p>
            <p><a href="https://cozyhealth.org">cozyhealth.org</a></p>
            <p>&copy; <?php echo e(date('Y')); ?> Cozy Health. All rights reserved.</p>
            <p style="font-size: 11px; margin-top: 15px;">
                This is an automated message. Please do not reply directly to this email unless directed otherwise.
            </p>
        </div>
    </div>
</body>
</html>
<?php /**PATH C:\projects\cozyhealth\cozy-health-api-main\resources\views/emails/welcome.blade.php ENDPATH**/ ?>