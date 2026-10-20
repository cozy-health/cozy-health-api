<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\StudentApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class StudentVerificationController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['enabled' => (bool) config('launch.student.enabled'), 'application' => StudentApplication::where('user_id', $request->user()->id)->latest()->first(), 'appeals_email' => 'Team@cozyhealth.org']);
    }
    public function store(Request $request)
    {
        abort_unless(config('launch.student.enabled'), 503, 'Student applications are not open yet.');
        $data = $request->validate(['institution' => ['required', 'string', 'max:200'], 'institution_email' => ['nullable', 'email:rfc', 'max:254'], 'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:3072'], 'acknowledged' => ['required', 'accepted']]);
        $path = $request->file('evidence')->store('student-evidence', 'local');
        try {
            $application = DB::transaction(function () use ($request, $data, $path) {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_if(StudentApplication::where('user_id', $request->user()->id)->where('status', 'pending')->exists(), 409, 'An application is already awaiting review.');
                return StudentApplication::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'institution' => $data['institution'], 'institution_email' => $data['institution_email'] ?? null, 'evidence_path' => $path, 'evidence_delete_at' => now()->addDays(config('launch.student.evidence_retention_days')), 'status' => 'pending']);
            });
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return response()->json(['application' => $application], 201);
    }
    public function sendVerification(Request $request, string $id)
    {
        $application = StudentApplication::where('user_id', $request->user()->id)->findOrFail($id);
        abort_unless($application->status === 'pending' && $application->institution_email, 422, 'A pending application with an institution email is required.');
        $token = Str::random(64);
        $application->update(['email_token_hash' => hash('sha256', $token), 'email_token_expires_at' => now()->addDay()]);
        $url = rtrim(config('app.url'), '/').'/api/v1/student-email/confirm?id='.$id.'&token='.$token;
        try { Mail::raw('Confirm the institution email for your Cozy Health student application: '.$url.'. Email confirmation does not itself approve student eligibility.', fn ($mail) => $mail->to($application->institution_email)->subject('Confirm your institution email')); }
        catch (\Throwable) { abort(503, 'Could not send confirmation. Your application remains available for manual review.'); }
        return response()->json(['message' => 'Confirmation requested. Manual enrolment review is still required.']);
    }
    public function confirm(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'token' => ['required', 'string', 'size:64']]);
        $application = StudentApplication::findOrFail($data['id']);
        abort_unless($application->email_token_hash && hash_equals($application->email_token_hash, hash('sha256', $data['token'])) && $application->email_token_expires_at?->isFuture(), 404);
        $application->update(['email_verified_at' => now(), 'email_token_hash' => null, 'email_token_expires_at' => null]);
        return response()->json(['message' => 'Institution email verified. Enrolment evidence still requires review.']);
    }
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'in:pending,approved,rejected']]);
        return response()->json(['data' => StudentApplication::when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))->latest()->paginate(20)]);
    }
    public function evidence(string $id)
    {
        $application = StudentApplication::findOrFail($id);
        abort_unless($application->evidence_path && $application->evidence_delete_at?->isFuture(), 404);
        return Storage::disk('local')->download($application->evidence_path, 'enrolment-evidence.'.pathinfo($application->evidence_path, PATHINFO_EXTENSION), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function review(Request $request, string $id)
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['required', 'string', 'min:5', 'max:2000'], 'current_enrolment_verified' => ['required_if:decision,approved', 'boolean']]);
        if ($data['decision'] === 'approved') abort_unless($data['current_enrolment_verified'] ?? false, 422, 'Current enrolment must be verified; an email address alone is insufficient.');
        $application = DB::transaction(function () use ($request, $data, $id) {
            $application = StudentApplication::lockForUpdate()->findOrFail($id);
            abort_unless($application->status === 'pending', 409, 'This application was already reviewed.');
            if ($data['decision'] === 'approved') abort_unless($application->evidence_path && $application->evidence_delete_at?->isFuture(), 422, 'Current evidence is required for approval.');
            $application->update(['status' => $data['decision'], 'reviewer_id' => $request->user()->id, 'review_note' => $data['note'], 'reviewed_at' => now(), 'eligible_until' => $data['decision'] === 'approved' ? now()->addMonths(config('launch.student.eligibility_months')) : null]);
            DB::table('student_review_events')->insert(['application_id' => $id, 'reviewer_id' => $request->user()->id, 'decision' => $data['decision'], 'note' => encrypt($data['note']), 'created_at' => now()]);
            return $application;
        });
        return response()->json(['application' => $application]);
    }
}
