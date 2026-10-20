<?php
namespace App\Services\Analytics;

use App\Services\AppConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AnalyticsService
{
    public const EVENTS = ['user_registered', 'user_logged_in', 'mood_logged', 'journal_created',
        'crisis_hub_opened', 'provider_linked', 'quiz_completed', 'app_opened'];

    public function track(string $event, array $properties = []): void
    {
        if (! in_array($event, self::EVENTS, true) || $properties !== []) {
            throw new InvalidArgumentException('Analytics accepts only registered event names without properties.');
        }
        try {
            if (! app(AppConfigService::class)->get()['flags']['analytics']) { return; }
            DB::table('analytics_events')->insert(['event' => $event, 'created_at' => now()]);
        } catch (Throwable) {
            // Monitoring must never break a successful user action or log its content.
            Log::warning('An aggregate analytics event could not be stored.');
        }
    }
}
