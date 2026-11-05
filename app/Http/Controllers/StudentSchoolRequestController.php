<?php

namespace App\Http\Controllers;

use App\Jobs\SendStudentSchoolNotice;
use App\Services\{AdminAudit, StudentDomainPolicy};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentSchoolRequestController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => DB::table('student_school_requests')->where('user_id', $request->user()->id)->latest()->get(['id', 'domain', 'institution', 'status', 'created_at', 'reviewed_at'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        abort_unless(config('launch.student.enabled'), 503, 'Student verification is not open yet.');
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'], 'institution' => 'required|string|max:200', 'website' => 'required|url:http,https|max:500']);
        abort_if(StudentDomainPolicy::personal($data['domain']), 422, 'Enter an institutional email domain, not a personal email provider.');
        abort_if(app(StudentDomainPolicy::class)->institution($data['domain']) !== null, 422, 'This domain already supports email verification.');
        $id = DB::transaction(function () use ($request, $data) {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('student_school_requests')->where('user_id', $request->user()->id)->where('domain', $data['domain'])->first();
            if ($existing) return $existing->id;
            abort_if(DB::table('student_school_requests')->where('user_id', $request->user()->id)->where('created_at', '>', now()->subDay())->count() >= 3, 429, 'You can submit three school requests per day.');
            $notify = !DB::table('student_school_requests')->where('domain', $data['domain'])->where('status', 'pending')->exists();
            $id = (string) Str::uuid();
            DB::table('student_school_requests')->insert(['id' => $id, 'user_id' => $request->user()->id, ...$data, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            if ($notify) foreach (['team@cozyhealth.org', 'cozyhealth.hq@gmail.com'] as $recipient) SendStudentSchoolNotice::dispatch($id, $recipient)->beforeCommit();
            return $id;
        });
        return response()->json(['data' => DB::table('student_school_requests')->where('id', $id)->first(['id', 'domain', 'institution', 'status', 'created_at', 'reviewed_at'])], 201)->header('Cache-Control', 'private, no-store');
    }

    public function queue(Request $request)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        return response()->json(['data' => DB::table('student_school_requests')->whereIn('id', DB::table('student_school_requests')->selectRaw('MIN(id)')->groupBy('domain', 'status'))->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest()->paginate(20)])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, string $id)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'note' => 'required|string|min:5|max:2000', 'nigerian_tertiary_confirmed' => 'required_if:decision,approved|boolean', 'institution_type' => 'required_if:decision,approved|in:university,polytechnic,college_of_education,tertiary']);
        abort_if($data['decision'] === 'approved' && !($data['nigerian_tertiary_confirmed'] ?? false), 422, 'Confirm Nigerian tertiary status and email domain ownership.');
        DB::transaction(function () use ($request, $data, $id) {
            $row = DB::table('student_school_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'pending', 409, 'Request already reviewed. Refresh the queue.');
            if ($data['decision'] === 'approved') {
                $domain = DB::table('student_email_domains')->where('domain', $row->domain)->lockForUpdate()->first();
                abort_if($domain && !$domain->enabled, 409, 'This domain is blocked. Review its domain settings separately.');
                if (!$domain) DB::table('student_email_domains')->insert(['domain' => $row->domain, 'institution' => $row->institution, 'country' => 'NG', 'institution_type' => $data['institution_type'], 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $requests = DB::table('student_school_requests')->where('domain', $row->domain)->where('status', 'pending')->orderBy('id')->lockForUpdate()->get();
            foreach ($requests as $item) {
            DB::table('student_school_requests')->where('id', $item->id)->update(['status' => $data['decision'], 'review_note' => encrypt($data['note']), 'reviewer_id' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, $data['decision'], 'student-school-requests', $item->id, ['status', 'domain']);
            $email = DB::table('users')->where('id', $item->user_id)->value('email');
            if ($email) SendStudentSchoolNotice::dispatch($item->id, $email, true)->beforeCommit();
            }
        }, 3);
        return response()->json(['message' => 'School request reviewed.']);
    }
}
