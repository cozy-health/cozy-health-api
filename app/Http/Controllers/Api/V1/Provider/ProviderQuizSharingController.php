<?php
namespace App\Http\Controllers\Api\V1\Provider;
use App\Http\Controllers\Controller;
use App\Services\QuizSharingService;
use App\Services\Provider\{ProviderAudit, ProviderClientAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ProviderQuizSharingController extends Controller
{
    private function query(Request $request)
    {
        $visible = app(\App\Services\Provider\ProviderOverviewData::class);
        return $visible->shares($request->user(), $visible->links($request->user()))
            ->select('quiz_shares.id', 'quiz_shares.user_id', 'quiz_shares.read_at', 'quiz_shares.quiz_attempt_id', 'attempts.quiz_title', 'attempts.completed_at', 'users.name as sender');
    }
    public function index(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $links = $request->user()->clientLinks()->active()->where('consent_quizzes', true)->lockForUpdate()->get()->keyBy('user_id');
            $page = $this->query($request)->orderByDesc('quiz_shares.created_at')->paginate(ProviderClientAccess::pagination($request));
            foreach ($page as $share) ProviderAudit::record($request, $links[$share->user_id], 'list', 'quiz', $share->quiz_attempt_id);
            return response()->json($page);
        });
    }
    public function show(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $share = $this->query($request)->where('quiz_shares.id', $id)->firstOrFail();
            $link = ProviderClientAccess::link($request, (string) $share->user_id, 'quizzes');
            $share = $this->query($request)->where('quiz_shares.id', $id)->lockForUpdate()->firstOrFail();
            $attempt = app(QuizSharingService::class)->owner($share->user_id, $share->quiz_attempt_id);
            DB::table('quiz_shares')->where('id', $id)->update(['read_at' => now()]);
            ProviderAudit::record($request, $link, 'view', 'quiz', $attempt->id);
            return response()->json(app(QuizSharingService::class)->summary($attempt));
        });
    }
}
