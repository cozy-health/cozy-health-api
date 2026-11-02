<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\BrandedNoticeMail;
use App\Models\StudentApplication;
use App\Models\User;
use App\Services\StudentEligibility;
use App\Services\StudentEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;

class StudentEmailController extends Controller
{
    public function store(Request $request, StudentEligibility $eligibility)
    {
        abort_unless(config('launch.student.enabled'), 503, 'Student applications are not open yet.');
        $data = $request->validate(['email' => 'required|email:rfc|max:254']);
        $email = strtolower(trim($data['email'])); $domain = Str::afterLast($email, '@');
        if (!DB::table('student_email_domains')->where('domain', $domain)->where('enabled', true)->exists()) {
            return response()->json(['code' => 'student_domain_unapproved', 'message' => 'This domain is not approved for automatic Student verification. Submit current enrolment documents for admin review.'], 422);
        }
        StudentEvidence::expireForUser($request->user()->id);
        $id = (string) Str::uuid(); $token = Str::random(64); $code = (string) random_int(100000, 999999);
        DB::transaction(function () use ($request, $eligibility, $id, $email, $domain, $token, $code) {
            $eligibility->assertCanApply($request->user()->id);
            $key = StudentEligibility::emailKey($email);
            // Do not disclose which account owns a school address.
            abort_if(DB::table('student_email_claims')->where('email_key', $key)->where('user_id', '!=', $request->user()->id)->exists(), 422, 'This school email cannot be used. Contact support or submit documents.');
            DB::table('student_email_challenges')->where('user_id', $request->user()->id)->delete();
            DB::table('student_email_challenges')->insert(['id' => $id, 'user_id' => $request->user()->id,
                'email' => encrypt($email), 'email_key' => $key, 'domain' => $domain,
                'token_hash' => hash('sha256', $token), 'code_hash' => StudentEligibility::emailKey($code),
                'attempts' => 0, 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            Mail::to($email)->send(new BrandedNoticeMail('Verify your student email', 'Verify your student email',
                ['Your verification code is '.$code.'. It expires in 15 minutes.', 'Return to Student verification in the Cozy Health account that requested this email and enter this code. You can also paste the verification link.', 'Verification unlocks Student purchase eligibility, not a paid subscription.'],
                [['label' => 'Student verification instructions', 'url' => rtrim(config('app.url'), '/').'/api/v1/student-email/confirm?id='.$id.'&token='.$token]]));
        } catch (\Throwable) {
            DB::table('student_email_challenges')->where('id', $id)->delete();
            abort(503, 'Could not send verification. Please retry or submit documents.');
        }
        return response()->json(['challenge_id' => $id, 'message' => 'Check your school inbox. Enter the code in this account within 15 minutes.']);
    }

    public function confirm(Request $request, StudentEligibility $eligibility)
    {
        abort_unless(config('launch.student.enabled'), 503, 'Student applications are not open yet.');
        $data = $request->validate(['id' => 'required|uuid', 'token' => ['required', 'string', 'regex:/^(?:[0-9]{6}|[A-Za-z0-9]{64})$/']]);
        StudentEvidence::expireForUser($request->user()->id);
        $result = DB::transaction(function () use ($request, $data, $eligibility) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $challenge = DB::table('student_email_challenges')->where('id', $data['id'])->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (!$challenge || \Illuminate\Support\Carbon::parse($challenge->expires_at)->lte(now()) || $challenge->attempts >= 5) return null;
            $valid = strlen($data['token']) === 6 ? hash_equals($challenge->code_hash, StudentEligibility::emailKey($data['token'])) : hash_equals($challenge->token_hash, hash('sha256', $data['token']));
            if (!$valid) {
                DB::table('student_email_challenges')->where('id', $challenge->id)->increment('attempts');
                return null;
            }
            $eligibility->assertCanApply($request->user()->id);
            $domain = DB::table('student_email_domains')->where('domain', $challenge->domain)->where('enabled', true)->lockForUpdate()->first();
            abort_unless($domain, 422, 'This domain is no longer approved. Submit documents instead.');
            DB::table('student_email_claims')->insertOrIgnore(['email_key' => $challenge->email_key, 'user_id' => $request->user()->id, 'created_at' => now()]);
            abort_unless((int) DB::table('student_email_claims')->where('email_key', $challenge->email_key)->value('user_id') === $request->user()->id, 422, 'This school email cannot be used. Contact support or submit documents.');
            $application = StudentApplication::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id,
                'institution' => $domain->institution, 'institution_email' => decrypt($challenge->email),
                'verification_method' => 'email', 'email_verified_at' => now(), 'status' => 'pending']);
            $eligibility->approve($application, null, 'Student-only school email ownership verified.');
            DB::table('student_email_challenges')->where('id', $challenge->id)->delete();
            return $application;
        }, 3);
        abort_unless($result, 422, 'Invalid or expired verification. Request a new code after five incorrect attempts.');
        return response()->json(['application' => $result, 'eligibility' => $eligibility->status($request->user()->id)])->header('Cache-Control', 'private, no-store');
    }
}
