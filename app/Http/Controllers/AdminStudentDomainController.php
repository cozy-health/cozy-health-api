<?php

namespace App\Http\Controllers;

use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminStudentDomainController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        return response()->json(['data' => DB::table('student_email_domains')->orderBy('domain')->paginate(20)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        if (is_string($request->input('domain'))) $request->merge(['domain' => strtolower(trim($request->input('domain')))]);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', 'unique:student_email_domains,domain'],
            'institution' => 'required|string|max:200', 'student_only_confirmed' => 'required|accepted']);
        $id = DB::transaction(function () use ($data, $request) {
            $id = DB::table('student_email_domains')->insertGetId(['domain' => $data['domain'], 'institution' => $data['institution'], 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, 'create', 'student-email-domains', (string) $id, ['domain', 'institution', 'student_only_confirmed']);
            return $id;
        });
        return response()->json(['data' => DB::table('student_email_domains')->find($id)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'version' => 'required|integer|min:1']);
        $row = DB::transaction(function () use ($data, $request, $id) {
            $row = DB::table('student_email_domains')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->version === $data['version'], 409, 'Domain changed. Refresh before saving.');
            DB::table('student_email_domains')->where('id', $id)->update(['enabled' => $data['enabled'], 'version' => $row->version + 1, 'updated_at' => now()]);
            AdminAudit::record($request->user()->id, $data['enabled'] ? 'enable' : 'disable', 'student-email-domains', $id, ['enabled']);
            return DB::table('student_email_domains')->find($id);
        });
        return response()->json(['data' => $row])->header('Cache-Control', 'private, no-store');
    }
}
