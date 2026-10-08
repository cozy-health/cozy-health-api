<?php

namespace Tests\Support;

use RuntimeException;

class AppleSigningKey
{
    public static function material(): array
    {
        static $material;
        if ($material === null) {
            $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048, 'curve_name' => 'prime256v1',
                'config' => __DIR__.'/../Fixtures/openssl.cnf'];
            $key = openssl_pkey_new($options);
            if ($key === false || ! openssl_pkey_export($key, $private, null, $options)) {
                throw new RuntimeException('Cannot generate ephemeral Apple test signing key.');
            }
            $material = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return $material;
    }

    public static function configure(): void
    {
        config([
            'services.apple.service_id' => 'service-id', 'services.apple.bundle_id' => 'bundle-id',
            'services.apple.team_id' => 'TEST_TEAM', 'services.apple.key_id' => 'TEST_KEY',
            'services.apple.private_key' => self::material()['private'], 'services.apple.redirect_uri' => null,
        ]);
    }
}
