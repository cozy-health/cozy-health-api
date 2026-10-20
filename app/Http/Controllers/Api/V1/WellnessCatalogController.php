<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class WellnessCatalogController extends ApiController
{
    public function achievements(Request $request)
    {
        $user = $request->user();
        app(\App\Services\AchievementService::class)->retain($user);
        return $this->success(DB::table('user_achievement_awards')->where('user_id', $user->id)->orderBy('earned_date')->get(['milestone', 'earned_date']));
    }

    public function catalog()
    {
        $data = ['mood_recording_version' => 2];
        foreach (['moods' => 'feelings', 'expressions' => 'feeling_expressions', 'triggers' => 'feeling_causes', 'coping' => 'coping_mechanisms', 'packages' => 'subscription_packages'] as $key => $table) {
            $data[$key] = DB::table($table)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        }
        $data['packages']->transform(function ($row) { $row->features = json_decode($row->features ?? '[]', true); return $row; });
        return $this->success($data);
    }
    public function challenges(Request $request)
    {
        $rows = DB::table('wellness_challenges')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id',
            DB::table('challenge_enrollments')->where('user_id', $request->user()->id)->select('challenge_id')))->orderBy('sort_order')->get();
        $rows->transform(function ($row) use ($request) {
            $row->tasks = json_decode($row->tasks, true);
            $enrollment = DB::table('challenge_enrollments')->where('user_id', $request->user()->id)->where('challenge_id', $row->id)->first();
            $row->enrolled = (bool) $enrollment;
            $row->completed_days = $enrollment ? DB::table('challenge_completions')->where('enrollment_id', $enrollment->id)->pluck('day') : [];
            if ($enrollment) { $definition = json_decode($enrollment->definition, true); $row->tasks = $definition['tasks']; $row->duration_days = $definition['duration_days']; }
            return $row;
        });
        return $this->success($rows);
    }
    public function enroll(Request $request, string $id)
    {
        $row = DB::table('wellness_challenges')->where('id', $id)->where('is_active', true)->firstOrFail();
        DB::table('challenge_enrollments')->insertOrIgnore(['user_id' => $request->user()->id, 'challenge_id' => $row->id,
            'definition' => json_encode(['tasks' => json_decode($row->tasks, true), 'duration_days' => $row->duration_days]), 'created_at' => now(), 'updated_at' => now()]);
        return $this->success(null, 'Enrolled.');
    }
    public function complete(Request $request, string $id)
    {
        $data = $request->validate(['day' => 'required|integer|min:1|max:90']);
        $enrollment = DB::table('challenge_enrollments')->where('user_id', $request->user()->id)->where('challenge_id', $id)->firstOrFail();
        $definition = json_decode($enrollment->definition, true);
        abort_if($data['day'] > count($definition['tasks']), 422, 'Task not found.');
        abort_if(now()->startOfDay()->lt(\Carbon\Carbon::parse($enrollment->created_at)->startOfDay()->addDays($data['day'] - 1)), 422, 'This day is not available yet.');
        DB::table('challenge_completions')->insertOrIgnore(['enrollment_id' => $enrollment->id, 'day' => $data['day'], 'completed_at' => now()]);
        return $this->success(null, 'Task completed.');
    }
}
