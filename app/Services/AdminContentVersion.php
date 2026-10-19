<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AdminContentVersion
{
    public static function token(object $content): string
    {
        return hash('sha256', json_encode([$content->id, $content->body, $content->status, $content->updated_at, $content->image_object_key ?? null], JSON_THROW_ON_ERROR));
    }

    public static function check(object $content, array $data, string $status): void
    {
        abort_unless(hash_equals(self::token($content), $data['expected_version']), 409, 'This content has changed. Reload it before making a decision.');
        if ($content->status === 'removed' && $status === 'published' && ! ($data['confirm_republish'] ?? false)) {
            throw ValidationException::withMessages(['confirm_republish' => 'Confirm that you intend to republish removed content.']);
        }
    }
}
