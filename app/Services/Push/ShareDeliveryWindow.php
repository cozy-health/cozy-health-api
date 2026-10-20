<?php
namespace App\Services\Push;
use Illuminate\Support\Facades\DB;

class ShareDeliveryWindow
{
    private static function query(int $userId, ?string $except = null)
    {
        return DB::table('quiz_push_attempts')->join('app_notifications', 'app_notifications.id', '=', 'quiz_push_attempts.notification_id')
            ->where('app_notifications.user_id', $userId)->when($except, fn ($q) => $q->where('notification_id', '!=', $except));
    }
    public static function latest(int $userId, ?string $except = null): ?string
    {
        return self::query($userId, $except)->max('quiz_push_attempts.created_at');
    }
    public static function count(int $userId, $from, $to, ?string $except = null): int
    {
        return self::query($userId, $except)->where('quiz_push_attempts.created_at', '>=', $from)->where('quiz_push_attempts.created_at', '<', $to)->distinct()->count('notification_id');
    }
}
