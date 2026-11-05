<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use App\Services\StudentEvidence;
use App\Services\StudentEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class StudentVerificationController extends Controller
{
    public function show(Request $request)
    {
        StudentEvidence::expireForUser($request->user()->id);
        $latestReview = DB::table('student_review_events')->selectRaw('MAX(id)')->whereColumn('application_id', 'student_applications.id');
        $application = StudentApplication::where('user_id', $request->user()->id)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()->orderByDesc($latestReview)->first();
        $challenge = DB::table('student_email_challenges')->where('user_id', $request->user()->id)->where('expires_at', '>', now())->where('attempts', '<', 3)->first(['id', 'expires_at']);
        return response()->json(['enabled' => (bool) config('launch.student.enabled'), 'application' => $application, 'eligibility' => app(StudentEligibility::class)->status($request->user()->id), 'email_challenge' => $challenge, 'appeals_email' => 'Team@cozyhealth.org'])->header('Cache-Control', 'private, no-store');
    }
    public function store(Request $request)
    {
        abort_unless(config('launch.student.documents_enabled', false), 422, 'Document applications are unavailable. Verify your school email instead.');
        abort_unless(config('launch.student.enabled'), 503, 'Student applications are not open yet.');
        StudentEvidence::expireForUser($request->user()->id);
        $data = $request->validate(['institution' => ['required', 'string', 'max:200'], 'institution_email' => ['nullable', 'email:rfc', 'max:254'], 'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:3072'], 'acknowledged' => ['required', 'accepted']]);
        $path = $request->file('evidence')->store('student-evidence', 'local');
        try {
            $application = DB::transaction(function () use ($request, $data, $path) {
                app(StudentEligibility::class)->assertCanApply($request->user()->id);
                DB::table('student_email_challenges')->where('user_id', $request->user()->id)->delete();
                return StudentApplication::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'institution' => $data['institution'], 'institution_email' => $data['institution_email'] ?? null, 'evidence_path' => $path, 'evidence_delete_at' => now()->addDays(config('launch.student.evidence_retention_days')), 'status' => 'pending']);
            });
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return response()->json(['application' => $application], 201);
    }
    public function sendVerification(Request $request, string $id)
    {
        $application = StudentApplication::where('user_id', $request->user()->id)->findOrFail($id);
        abort_unless(StudentEvidence::current($application) && $application->institution_email, 422, 'A current pending application with an institution email is required.');
        $token = Str::random(64);
        $application->update(['email_token_hash' => hash('sha256', $token), 'email_token_expires_at' => now()->addDay()]);
        $url = rtrim(config('app.url'), '/').'/api/v1/student-email/confirm?id='.$id.'&token='.$token;
        try {
            Mail::to($application->institution_email)->send(new \App\Mail\BrandedNoticeMail(
                'Confirm your institution email', 'Confirm your institution email',
                ['Confirm the institution email for your Cozy Health student application.', 'Email confirmation does not itself approve student eligibility.'],
                [['label' => 'Confirm institution email', 'url' => $url]]
            ));
        }
        catch (\Throwable) { abort(503, 'Could not send confirmation. Your application remains available for manual review.'); }
        return response()->json(['message' => 'Confirmation requested. Manual enrolment review is still required.']);
    }
    public function confirm(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'token' => ['required', 'string', 'size:64']]);
        if (DB::table('student_email_challenges')->where('id', $data['id'])->exists()) {
            return response()->json(['message' => 'Open Student verification in the Cozy Health account that requested this email. Enter the code from your email or paste this link there. Opening a link does not grant eligibility.'])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
        $application = StudentApplication::findOrFail($data['id']);
        abort_unless(StudentEvidence::current($application) && $application->email_token_hash && hash_equals($application->email_token_hash, hash('sha256', $data['token'])) && $application->email_token_expires_at?->isFuture(), 404);
        $application->update(['email_verified_at' => now(), 'email_token_hash' => null, 'email_token_expires_at' => null]);
        return response()->json(['message' => 'Institution email verified. Enrolment evidence still requires review.']);
    }
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'in:pending,approved,rejected,expired'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $rows = StudentApplication::when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))->latest()->orderByDesc('id')->paginate(20);
        $rows->through(function ($application) {
            $application->evidence_available = StudentEvidence::current($application);
            $application->evidence_cleanup_pending = $application->evidence_path && !StudentEvidence::current($application);
            return $application;
        });
        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }
    public function evidence(string $id)
    {
        $application = StudentApplication::findOrFail($id);
        abort_unless(StudentEvidence::current($application), 404);
        return Storage::disk('local')->download($application->evidence_path, 'enrolment-evidence.'.pathinfo($application->evidence_path, PATHINFO_EXTENSION), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function review(Request $request, string $id)
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['required', 'string', 'min:5', 'max:2000'], 'current_enrolment_verified' => ['required_if:decision,approved', 'boolean']]);
        if ($data['decision'] === 'approved') abort_unless($data['current_enrolment_verified'] ?? false, 422, 'Current enrolment must be verified; an email address alone is insufficient.');
        $application = DB::transaction(function () use ($request, $data, $id) {
            $owner = StudentApplication::findOrFail($id)->user_id;
            \App\Models\User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $application = StudentApplication::lockForUpdate()->findOrFail($id);
            abort_unless($application->status === 'pending', 409, 'This application was already reviewed.');
            abort_unless(StudentEvidence::current($application), 422, 'This evidence expired. Ask the student to submit a new application.');
            if ($data['decision'] === 'approved') {
                app(StudentEligibility::class)->approve($application, $request->user()->id, $data['note']);
            } else {
                $application->update(['status' => 'rejected', 'reviewer_id' => $request->user()->id, 'review_note' => $data['note'], 'reviewed_at' => now(), 'eligible_until' => null, 'evidence_delete_at' => now(), 'email_token_hash' => null, 'email_token_expires_at' => null]);
                DB::table('student_review_events')->insert(['application_id' => $id, 'reviewer_id' => $request->user()->id, 'decision' => 'rejected', 'note' => encrypt($data['note']), 'created_at' => now()]);
            }
            return $application;
        });
        $deleted = StudentEvidence::delete($application);
        return response()->json(['application' => $application, 'evidence_deleted' => $deleted]);
    }
}
