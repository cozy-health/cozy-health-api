<?php
namespace App\Services;
use App\Models\{User, Provider};
use Illuminate\Mail\Mailable;
class AdminEmailRegistry
{
    public const EVENTS = [
        'welcome' => ['WelcomeMail', 'Account registration'],
        'verification' => ['VerifyEmailMail', 'Email verification request'],
        'password-reset' => ['PasswordResetRequestMail', 'Patient password reset request'],
        'password-confirmation' => ['PasswordResetConfirmationMail', 'Patient password changed'],
        'provider-reset' => ['ProviderPasswordResetMail', 'Provider password reset request'],
        'provider-confirmation' => ['ProviderPasswordResetConfirmationMail', 'Provider password changed'],
        'provider-invitation' => ['ProviderInvitationMail', 'Patient provider connection invitation'],
        'new-device' => ['NewDeviceLoginMail', 'New device sign-in'],
        'account-deleted' => ['AccountDeletedMail', 'Account deletion'],
        'quiz-share' => ['QuizShareMail', 'Assessment sharing invitation'],
    ];
    public static function event(string $class): ?string
    {
        foreach (self::EVENTS as $key => [$name]) if (class_basename($class) === $name) return $key;
        return null;
    }
    public static function preview(string $event): Mailable
    {
        $user = new User(['name' => 'Preview User', 'email' => 'preview@example.test']);
        $provider = new Provider(['name' => 'Preview Provider', 'email' => 'preview@example.test']);
        return match ($event) {
            'welcome' => new \App\Mail\WelcomeMail($user),
            'verification' => new \App\Mail\VerifyEmailMail('000000'),
            'password-reset' => new \App\Mail\PasswordResetRequestMail($user, '000000'),
            'password-confirmation' => new \App\Mail\PasswordResetConfirmationMail($user),
            'provider-reset' => new \App\Mail\ProviderPasswordResetMail($provider, '000000'),
            'provider-confirmation' => new \App\Mail\ProviderPasswordResetConfirmationMail($provider),
            'provider-invitation' => \App\Mail\ProviderInvitationMail::example(),
            'new-device' => new \App\Mail\NewDeviceLoginMail('Preview device', null, now()->toIso8601String()),
            'account-deleted' => new \App\Mail\AccountDeletedMail,
            'quiz-share' => new \App\Mail\QuizShareMail('https://example.test/preview-only'),
            default => abort(404),
        };
    }
}
