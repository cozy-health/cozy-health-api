<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\{ProviderClientLink, QuizAttempt, User, UserProfile};
use App\Services\{AssessmentService, QuizSharingService};
use App\Services\Provider\ProviderAudit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuizSharingController extends ApiController
{
    public function __construct(private QuizSharingService $sharing) {}
    private function outward(Request $request): void
    {
        app(AssessmentService::class)->enabled();
        abort_if($request->user()->is_demo, 403, 'Demo results cannot be shared.');
    }
    public function resolve(Request $request)
    {
        $data = $request->validate(['username' => 'required|string|max:50']);
        $profile = UserProfile::withoutGlobalScope('user')->where('username_key', strtolower(trim($data['username'])))->firstOrFail();
        $user = User::whereKey($profile->user_id)->where('is_active', true)->where('is_demo', false)->firstOrFail();
        return $this->success(['id' => $user->id, 'username' => $profile->username]);
    }
    public function friend(Request $request, string $id)
    {
        $this->outward($request);
        $data = $request->validate(['recipient_id' => 'required|integer', 'consent' => 'required|accepted']);
        return $this->success($this->sharing->friend($request->user()->id, $id, $data['recipient_id']));
    }
    public function provider(Request $request, string $id)
    {
        $this->outward($request);
        $data = $request->validate(['provider_id' => 'required|integer', 'consent' => 'required|accepted', 'enable_quiz_consent' => 'sometimes|boolean']);
        return DB::transaction(function () use ($request, $id, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $link = ProviderClientLink::where('user_id', $request->user()->id)->where('provider_id', $data['provider_id'])->active()
                ->whereHas('provider', fn ($q) => $q->where('is_active', true)->where('is_verified', true))->lockForUpdate()->firstOrFail();
            $this->sharing->owner($request->user()->id, $id);
            if (! $link->consent_quizzes && ($data['enable_quiz_consent'] ?? false)) $link->update(['consent_quizzes' => true]);
            abort_unless($link->consent_quizzes, 403, 'Confirm quiz consent before sharing.');
            $old = DB::table('quiz_shares')->where('quiz_attempt_id', $id)->where('provider_id', $link->provider_id)->first();
            if (! $old || $old->revoked_at || $old->link_id !== $link->id) {
                DB::table('quiz_shares')->updateOrInsert(['quiz_attempt_id' => $id, 'provider_id' => $link->provider_id],
                    ['user_id' => $request->user()->id, 'link_id' => $link->id, 'consented_at' => now(), 'revoked_at' => null,
                        'read_at' => null, 'revision' => ($old?->revision ?? 0) + 1, 'created_at' => now(), 'updated_at' => now()]);
                ProviderAudit::record($request, $link, 'shared', 'quiz', $id);
            }
            return $this->success(['attempt_id' => $id, 'provider_id' => $link->provider_id, 'shared' => true]);
        });
    }
    public function invitation(Request $request, string $id)
    {
        $this->outward($request);
        $request->validate(['consent' => 'required|accepted']);
        return DB::transaction(function () use ($request, $id) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->sharing->owner($request->user()->id, $id);
            $token = bin2hex(random_bytes(32));
            $invite = (string) Str::uuid();
            DB::table('quiz_invitations')->insert(['id' => $invite, 'user_id' => $request->user()->id, 'quiz_attempt_id' => $id,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            return $this->success(['id' => $invite, 'url' => rtrim(config('app.url'), '/').'/quiz-invite/'.$token, 'expires_at' => now()->addDays(7)]);
        });
    }
    public function claim(Request $request)
    {
        $this->outward($request);
        $data = $request->validate(['token' => 'required|string|regex:/^[a-f0-9]{64}$/', 'consent' => 'required|accepted']);
        return DB::transaction(function () use ($request, $data) {
            $lookup = DB::table('quiz_invitations')->where('token_hash', hash('sha256', $data['token']))->first();
            abort_unless($lookup, 404);
            // Owner lock is shared with deletion and grant revocation.
            User::whereKey($lookup->user_id)->lockForUpdate()->firstOrFail();
            $invite = DB::table('quiz_invitations')->where('id', $lookup->id)->lockForUpdate()->first();
            abort_if($invite->revoked_at, 410, 'This invitation is no longer available.');
            abort_if($invite->user_id === $request->user()->id, 422, 'This is your own invitation.');
            abort_if($invite->recipient_id && $invite->recipient_id !== $request->user()->id, 410, 'Invitation already accepted.');
            if ($invite->recipient_id) {
                $share = DB::table('quiz_friend_shares')->where('quiz_attempt_id', $invite->quiz_attempt_id)->where('recipient_id', $invite->recipient_id)->whereNull('revoked_at')->first();
                abort_unless($share, 410, 'This result is no longer shared.');
                $this->sharing->owner($invite->user_id, $invite->quiz_attempt_id);
                return $this->success(['share_id' => $share->id]);
            }
            abort_if(now()->gt($invite->expires_at), 410, 'This invitation has expired.');
            abort_unless($request->user()->profile?->onboarding_completed_at, 409, 'Finish onboarding before accepting.');
            $share = $this->sharing->friend($invite->user_id, $invite->quiz_attempt_id, $request->user()->id);
            DB::table('quiz_invitations')->where('id', $invite->id)->update(['recipient_id' => $request->user()->id, 'accepted_at' => now(), 'updated_at' => now()]);
            return $this->success(['share_id' => $share->id]);
        });
    }
    public function grants(Request $request, string $id)
    {
        $this->sharing->owner($request->user()->id, $id, false);
        $result = [];
        foreach (['friend' => 'quiz_friend_shares', 'provider' => 'quiz_shares', 'invitation' => 'quiz_invitations'] as $kind => $table) {
            $result[$kind] = DB::table($table)->where('user_id', $request->user()->id)->where('quiz_attempt_id', $id)
                ->get()->map(function ($row) { unset($row->token_hash); return $row; });
        }
        return $this->success($result);
    }
    public function revoke(Request $request, string $id, string $kind, string $shareId)
    {
        $this->sharing->revoke($request->user()->id, $id, $kind, $shareId);
        return $this->success(['revoked' => true]);
    }
    public function received(Request $request)
    {
        return $this->success($this->receivedQuery($request)->orderByDesc('quiz_friend_shares.created_at')->paginate(20));
    }
    private function receivedQuery(Request $request, bool $inbox = true)
    {
        return DB::table('quiz_friend_shares')->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_friend_shares.quiz_attempt_id')
            ->join('users', 'users.id', '=', 'quiz_friend_shares.user_id')->where('recipient_id', $request->user()->id)
            ->whereNull('quiz_friend_shares.revoked_at')->when($inbox, fn ($q) => $q->whereNull('dismissed_at'))->whereNull('quiz_attempts.deleted_at')
            ->where('quiz_attempts.is_deleted', false)->whereNotNull('validated_at')
            ->select('quiz_friend_shares.id', 'quiz_friend_shares.user_id', 'read_at', 'quiz_title', 'completed_at', 'users.name as sender');
    }
    public function receivedDetail(Request $request, string $id)
    {
        $share = DB::table('quiz_friend_shares')->where('id', $id)->where('recipient_id', $request->user()->id)->firstOrFail();
        return DB::transaction(function () use ($request, $id, $share) {
            User::whereKey($share->user_id)->lockForUpdate()->firstOrFail();
            $this->receivedQuery($request, false)->where('quiz_friend_shares.id', $id)->lockForUpdate()->firstOrFail();
            $attempt = $this->sharing->owner($share->user_id, $share->quiz_attempt_id);
            DB::table('quiz_friend_shares')->where('id', $id)->update(['read_at' => now()]);
            return $this->success($this->sharing->summary($attempt));
        });
    }
    public function dismiss(Request $request, string $id)
    {
        DB::table('quiz_friend_shares')->where('id', $id)->where('recipient_id', $request->user()->id)->update(['dismissed_at' => now()]);
        return $this->success(['dismissed' => true]);
    }
    public function pdf(Request $request, string $id)
    {
        $result = $this->sharing->summary($this->sharing->owner($request->user()->id, $id));
        $pdf = Pdf::loadView('quiz.result', compact('result'))->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]);
        return response($pdf->output())->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="assessment-result.pdf"')->header('Cache-Control', 'private, no-store');
    }
}
