<?php

namespace App\Services;

use Illuminate\Support\Carbon;

final class StorePlanPresentation
{
    public static function links(string $platform): ?array
    {
        return match ($platform) {
            'apple' => ['store' => 'Apple App Store', 'manage_url' => 'https://apps.apple.com/account/subscriptions', 'history_url' => 'https://reportaproblem.apple.com/'],
            'google' => ['store' => 'Google Play', 'manage_url' => 'https://play.google.com/store/account/subscriptions', 'history_url' => 'https://play.google.com/store/account/orderhistory'],
            default => null,
        };
    }

    public static function message(array $details): array
    {
        $state = $details['state'];
        $store = self::links($details['platform']);
        $plan = ucfirst($details['tier']);
        $title = match ($state) {
            'trial' => 'Your Cozy trial is active',
            'canceled' => 'Your Cozy subscription will end',
            'grace_period', 'billing_retry', 'on_hold' => 'Your Cozy subscription needs attention',
            'expired' => 'Your Cozy subscription has ended',
            'revoked' => 'Your Cozy subscription access has changed',
            'paused' => 'Your Cozy subscription is paused',
            default => 'Your Cozy plan is active',
        };
        $paragraphs = [$plan.' subscription through '.$store['store'].'.'];
        $paragraphs[] = match ($state) {
            'trial' => 'Your trial access is confirmed. This is a plan-status update, not a payment receipt.',
            'canceled' => 'Automatic renewal is off. You keep the confirmed access shown below until it ends.',
            'grace_period' => 'The store reports a billing problem and a temporary grace period. Check your payment method in the store.',
            'billing_retry', 'on_hold' => 'The store has not confirmed continued subscription access. Check your payment method in the store. Do not purchase again just to retry verification.',
            'expired' => 'The store reports that this subscription has expired.',
            'revoked' => 'The store has removed access for this subscription. Check its purchase history for any refund information.',
            'paused' => 'The store reports that this subscription is paused.',
            default => 'The store has confirmed your plan access.',
        };
        $date = Carbon::parse($details['expires_at'])->utc()->format('j M Y, H:i').' UTC';
        $label = match ($state) {
            'grace_period' => 'Grace-period access ends',
            'revoked', 'on_hold', 'paused', 'billing_retry' => 'Store-recorded period end',
            'expired' => 'Previous period end',
            default => in_array($state, ['active', 'trial'], true) && ($details['auto_renews'] ?? null) === true ? 'Scheduled renewal' : 'Confirmed access end',
        };
        $paragraphs[] = $label.': '.$date.'.';
        $paragraphs[] = 'Apple or Google supplies payment receipts and tax details. This email does not request another payment.';
        if (($details['environment'] ?? '') === 'sandbox') array_unshift($paragraphs, 'TEST STORE SUBSCRIPTION — not a real payment confirmation.');
        return ['title' => $title, 'paragraphs' => $paragraphs, 'actions' => [
            ['label' => 'Manage subscription', 'url' => $store['manage_url']],
            ['label' => 'View store purchase history', 'url' => $store['history_url']],
        ]];
    }
}
