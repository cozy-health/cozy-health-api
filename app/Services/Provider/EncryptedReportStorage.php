<?php

namespace App\Services\Provider;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EncryptedReportStorage
{
    public const PREFIX = "COZY-PDF-AES256-V1\n";

    public function encrypt(string $pdf): string
    {
        if (! in_array(config('app.cipher'), ['AES-256-CBC', 'AES-256-GCM'], true)) {
            throw new RuntimeException('Provider reports require AES-256 encryption.');
        }
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Invalid provider PDF.');
        }

        return self::PREFIX.Crypt::encryptString($pdf);
    }

    public function decrypt(string $bytes): string
    {
        if (! str_starts_with($bytes, self::PREFIX)) {
            throw new RuntimeException('Provider report encryption migration is required.');
        }
        $pdf = Crypt::decryptString(substr($bytes, strlen(self::PREFIX)));
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Invalid encrypted provider PDF.');
        }

        return $pdf;
    }

    public function migrate(string $path): bool
    {
        $disk = Storage::disk('local');
        $bytes = $disk->get($path);
        if (str_starts_with($bytes, self::PREFIX)) {
            $this->decrypt($bytes);

            return false;
        }
        $temporary = $path.'.encrypting';
        try {
            if (! $disk->put($temporary, $this->encrypt($bytes)) ||
                ! hash_equals($bytes, $this->decrypt($disk->get($temporary)))) {
                throw new RuntimeException('Unable to verify encrypted provider report.');
            }
            // Local Flysystem rename replaces the original only after verification.
            if (! $disk->move($temporary, $path)) {
                throw new RuntimeException('Unable to replace provider report.');
            }
        } finally {
            $disk->delete($temporary);
        }

        return true;
    }
}
