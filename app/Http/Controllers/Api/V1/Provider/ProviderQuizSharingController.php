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
        abort_unless($request->user()->is_verified, 403, 'Provider verification is required.');
        return DB::table('quiz_shares')->join('provider_client_links as links', 'links.id', '=', 'quiz_shares.link_id')
            ->join('quiz_attempts as attempts', 'attempts.id', '=', 'quiz_shares.quiz_attempt_id')->join('users', 'users.id', '=', 'quiz_shares.user_id')
            ->where('quiz_shares.provider_id', $request->user()->id)->where('links.provider_id', $request->user()->id)
            ->whereNull('links.revoked_at')->whereNotNull('links.verified_at')->where('links.consent_quizzes', true)
            ->whereNull('quiz_shares.revoked_at')->whereNull('attempts.deleted_at')->where('attempts.is_deleted', false)->whereNotNull('attempts.validated_at')
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
        $share = $this->query($request)->where('quiz_shares.id', $id)->firstOrFail();
        return DB::transaction(function () use ($request, $id, $share) {
            $link = ProviderClientAccess::link($request, (string) $share->user_id, 'quizzes');
            $share = $this->query($request)->where('quiz_shares.id', $id)->lockForUpdate()->firstOrFail();
            $attempt = app(QuizSharingService::class)->owner($share->user_id, $share->quiz_attempt_id);
            DB::table('quiz_shares')->where('id', $id)->update(['read_at' => now()]);
            ProviderAudit::record($request, $link, 'view', 'quiz', $attempt->id);
            return response()->json(app(QuizSharingService::class)->summary($attempt));
        });
    }
}
