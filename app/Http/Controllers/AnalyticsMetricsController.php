<?php
namespace App\Http\Controllers;
use App\Http\Requests\AnalyticsMetricsRequest;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\DB;

class AnalyticsMetricsController extends Controller
{
    public function __invoke(AnalyticsMetricsRequest $request)
    {
        $days = (int) rtrim($request->validated('range', '30d'), 'd');
        $start = now()->subDays($days);
        $active = DB::table('personal_access_tokens as tokens')->join('users', 'users.id', '=', 'tokens.tokenable_id')
            ->where('tokens.tokenable_type', (new User)->getMorphClass())->whereNull('users.deleted_at')
            ->whereRaw('COALESCE(tokens.last_used_at, tokens.created_at) >= ?', [$start])
            ->where(fn ($query) => $query->whereNull('tokens.expires_at')->orWhere('tokens.expires_at', '>', now()))
            ->distinct()->count('tokens.tokenable_id');
        $counts = DB::table('analytics_events')->where('created_at', '>=', $start)
            ->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event');
        $cohort = max($active, User::where('created_at', '>=', $start)->count());
        $suppress = fn (int $count) => $cohort < 5 || $count < 5 ? '<5' : $count;
        return response()->json([
            'events' => array_map(fn ($event) => ['event' => $event, 'count' => $suppress((int) ($counts[$event] ?? 0))], AnalyticsService::EVENTS),
            'active_users' => $active < 5 ? '<5' : $active,
            'new_signups' => $suppress(User::where('created_at', '>=', $start)->count()),
        ]);
    }
}
