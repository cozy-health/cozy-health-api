<?php

namespace App\Services;

final class StoreBillingReadiness
{
    public function platform(string $platform): bool
    {
        if (!config('launch.billing_enabled')) return false;
        $fields = match ($platform) {
            'apple' => ['issuer_id', 'key_id', 'private_key', 'bundle_id', 'root_ca'],
            'google' => ['package_name', 'credentials', 'webhook_audience', 'webhook_email'],
            default => [],
        };
        if (!$fields) return false;
        foreach ($fields as $field) if (!config('launch.'.$platform.'.'.$field)) return false;
        foreach (config('launch.products', []) as $product) {
            if (is_array($product) && ($product['platform'] ?? '') === $platform && in_array($product['tier'] ?? '', ['student', 'standard', 'premium'], true)) return true;
        }
        return false;
    }
}
