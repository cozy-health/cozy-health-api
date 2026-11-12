<?php
namespace App\Services;

use RuntimeException;

final class BackupCipher
{
    private const MAGIC = "COZYBK1\n";
    private $output;
    private $state;
    private $deflater;
    private $plainHash;
    private $cipherHash;
    private int $plainBytes = 0;
    private int $cipherBytes = 0;
    public function __construct(string $path, string $key, bool $gzip)
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) throw new RuntimeException('Authenticated streaming encryption is unavailable.');
        $this->output = fopen($path, 'xb');
        if (! $this->output || ! chmod($path, 0600)) throw new RuntimeException('Private backup work file unavailable.');
        [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $this->deflater = $gzip ? deflate_init(ZLIB_ENCODING_GZIP) : null;
        $this->plainHash = hash_init('sha256'); $this->cipherHash = hash_init('sha256');
        $this->write(self::MAGIC.$header);
    }
    private function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) { $n = fwrite($this->output, substr($bytes, $offset)); if ($n === false || $n === 0) throw new RuntimeException('Encrypted backup write failed.'); $offset += $n; }
        hash_update($this->cipherHash, $bytes); $this->cipherBytes += strlen($bytes);
    }
    private function frame(string $bytes, int $tag): void
    {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $bytes, '', $tag);
        $this->write(pack('N', strlen($cipher)).$cipher);
    }
    public function append(string $bytes): void
    {
        $this->plainBytes += strlen($bytes);
        if ($this->plainBytes > config('development-backup.max_plain_bytes')) throw new RuntimeException('Backup plaintext sizing limit exceeded.');
        hash_update($this->plainHash, $bytes);
        $bytes = $this->deflater ? deflate_add($this->deflater, $bytes, ZLIB_NO_FLUSH) : $bytes;
        if ($bytes === false) throw new RuntimeException('Backup compression failed.');
        for ($i = 0; $i < strlen($bytes); $i += 65536) $this->frame(substr($bytes, $i, 65536), \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
    }
    public function finish(): array
    {
        $tail = $this->deflater ? deflate_add($this->deflater, '', ZLIB_FINISH) : '';
        if ($tail === false) throw new RuntimeException('Backup compression finalization failed.');
        for ($i = 0; $i < strlen($tail); $i += 65536) $this->frame(substr($tail, $i, 65536), \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        $this->frame('', \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        fclose($this->output); $this->output = null;
        return ['plain_bytes' => $this->plainBytes, 'cipher_bytes' => $this->cipherBytes, 'plain_sha256' => hash_final($this->plainHash), 'cipher_sha256' => hash_final($this->cipherHash), 'gzip' => $this->deflater !== null];
    }
    public function close(): void { if (is_resource($this->output)) fclose($this->output); $this->output = null; }
    public function __destruct() { $this->close(); }
    private static function readExact($input, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) { $part = fread($input, $length - strlen($data)); if ($part === false || $part === '') throw new RuntimeException('Truncated encrypted backup.'); $data .= $part; }
        return $data;
    }
    public static function verify($input, string $key, array $expected, ?callable $consume = null): void
    {
        $cipherHash = hash_init('sha256');
        $header = self::readExact($input, 8 + \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        hash_update($cipherHash, $header);
        if (substr($header, 0, 8) !== self::MAGIC) throw new RuntimeException('Invalid encrypted backup header.');
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 8), $key);
        $inflate = $expected['gzip'] ? inflate_init(ZLIB_ENCODING_GZIP) : null;
        $hash = hash_init('sha256'); $count = 0; $total = strlen($header);
        do {
            $prefix = self::readExact($input, 4); $length = unpack('Nlength', $prefix)['length'];
            if ($length < 17 || $length > 65553) throw new RuntimeException('Invalid encrypted backup frame.');
            $cipher = self::readExact($input, $length); $total += 4 + $length;
            hash_update($cipherHash, $prefix.$cipher);
            $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($plain === false) throw new RuntimeException('Backup authentication failed.');
            [$bytes, $tag] = $plain;
            if ($inflate && $bytes !== '') { $bytes = inflate_add($inflate, $bytes, ZLIB_SYNC_FLUSH); if ($bytes === false) throw new RuntimeException('Backup decompression failed.'); }
            $count += strlen($bytes);
            if ($count > config('development-backup.max_plain_bytes')) throw new RuntimeException('Backup verification limit exceeded.');
            hash_update($hash, $bytes);
            if ($consume !== null && $bytes !== '') $consume($bytes);
        } while ($tag !== \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        if (($inflate && inflate_get_status($inflate) !== ZLIB_STREAM_END) || fread($input, 1) !== '' || $count !== $expected['plain_bytes'] || $total !== $expected['cipher_bytes'] || ! hash_equals($expected['plain_sha256'], hash_final($hash)) || ! hash_equals($expected['cipher_sha256'], hash_final($cipherHash))) throw new RuntimeException('Backup checksum or length verification failed.');
    }
}
