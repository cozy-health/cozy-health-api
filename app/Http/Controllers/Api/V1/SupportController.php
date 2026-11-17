<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportReportRequest;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\SupportThreads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    public function store(SupportReportRequest $request)
    {
        $data = $request->validated();
        $report = DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = isset($data['request_id']) ? SupportReport::where('user_id', $request->user()->id)->where('request_id', $data['request_id'])->first() : null;
            if ($existing) {
                abort_unless($existing->subject === $data['subject'] && $existing->message === $data['message'], 409, 'This request was already submitted with different content.');

                return $existing;
            }

            return $request->user()->supportReports()->create($data)->refresh();
        });

        return response()->json(['success' => true, 'data' => $report->only(['id', 'subject', 'message', 'status', 'created_at']), 'message' => 'Problem report submitted successfully.'], 201)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json(['data' => SupportReport::where('user_id', $request->user()->id)->latest('id')->paginate(20, ['id', 'subject', 'status', 'created_at', 'updated_at'], 'page', $data['page'] ?? 1)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id, SupportThreads $threads)
    {
        return response()->json(['data' => $threads->detail(SupportReport::where('user_id', $request->user()->id)->findOrFail($id))])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $id, SupportThreads $threads)
    {
        $data = $request->validate(['body' => 'required|string|max:10000', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $threads->detail($threads->reply($id, $data, 'customer', $request->user()))])->header('Cache-Control', 'private, no-store');
    }
}
