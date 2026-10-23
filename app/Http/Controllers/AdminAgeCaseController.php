<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AgeEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAgeCaseController extends Controller
{
    public function index(Request $request, AgeEligibility $eligibility)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $cases = DB::table('age_support_cases')->orderByDesc('created_at')->paginate(20);
        foreach ($cases as $case) {
            $case->message = decrypt($case->message);
            $case->resolution = $case->resolution ? decrypt($case->resolution) : null;
            $user = User::find($case->user_id);
            $case->eligibility = $user ? $eligibility->state($user) : null;
            if ($user) $eligibility->event($user, 'admin_case_viewed', ['case_id' => $case->id], actor: $request->user()->id);
        }
        return response()->json(['data' => $cases])->header('Cache-Control', 'private, no-store');
    }

    public function resolve(Request $request, string $id, AgeEligibility $eligibility)
    {
        $data = $request->validate(['action' => 'required|in:assign,resolve,correct_dob,clear_dispute',
            'note' => 'required|string|min:10|max:2000', 'date_of_birth' => 'required_if:action,correct_dob|date_format:Y-m-d|before:today',
            'authority_checked' => in_array($request->input('action'), ['correct_dob', 'clear_dispute']) ? 'required|accepted' : 'exclude']);
        if (in_array($data['action'], ['correct_dob', 'clear_dispute'])) {
            abort_unless(config('age.correction_approval'), 409, 'An approved correction/authority-check procedure is required. Leave unresolved cases restricted.');
            $request->validate(['verification_reference' => 'required|string|min:5|max:200']);
        }
        return DB::transaction(function () use ($request, $id, $data, $eligibility) {
            $candidate = DB::table('age_support_cases')->where('id', $id)->firstOrFail();
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $case = DB::table('age_support_cases')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($case->status === 'open', 409, 'This case has already been resolved.');
            if ($data['action'] === 'assign') {
                DB::table('age_support_cases')->where('id', $id)->update(['assignee_id' => $request->user()->id, 'updated_at' => now()]);
            } else {
                abort_unless($case->assignee_id === $request->user()->id, 409, 'Assign this case to yourself before resolving it.');
                if (in_array($data['action'], ['correct_dob', 'clear_dispute'])) {
                    $eligibility->endAuthorizations($user, 'review_required');
                    $user->forceFill(['age_disputed' => false, ...($data['action'] === 'correct_dob'
                        ? ['date_of_birth' => $data['date_of_birth'], 'age_declared_at' => now()] : [])])->save();
                }
                DB::table('age_support_cases')->where('id', $id)->update(['status' => 'resolved', 'resolution' => encrypt($data['note']), 'updated_at' => now()]);
                DB::afterCommit(function () use ($user) {
                    if (! $user->email_verified_at) return;
                    try { \Illuminate\Support\Facades\Mail::raw('Your Cozy Health age/guardian support case has been updated. Sign in and open Age & Guardian to review the response. This message contains no private records.', fn ($mail) => $mail->to($user->email)->subject('Cozy Health support case update')); }
                    catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Age support case update email unavailable.'); }
                });
            }
            $eligibility->event($user, 'admin_case_'.$data['action'], ['case_id' => $id, 'note' => $data['note'],
                'verification_reference' => $request->input('verification_reference'), 'corrected_dob' => $data['date_of_birth'] ?? null], actor: $request->user()->id);
            return response()->json(['message' => 'Case updated. Age, country and consent requirements still apply.']);
        });
    }
}
