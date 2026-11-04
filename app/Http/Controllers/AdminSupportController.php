<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportReport;
use App\Services\AdminAudit;
use App\Services\SupportThreads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSupportController extends Controller
{
    public function show(Request $request, int $id, SupportThreads $threads)
    {
        $report = SupportReport::findOrFail($id);
        AdminAudit::record($request->user()->id, 'read', 'support-reports', (string) $id);

        return response()->json(['data' => $threads->detail($report, true)])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $id, SupportThreads $threads)
    {
        $data = $request->validate(['body' => 'required|string|max:10000', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $threads->detail($threads->reply($id, $data, 'support', $request->user()), true)])->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, int $id, string $messageId, SupportThreads $threads)
    {
        DB::transaction(function () use ($request, $id, $messageId, $threads) {
            $report = SupportReport::whereKey($id)->lockForUpdate()->firstOrFail();
            $message = SupportMessage::where('support_report_id', $id)->where('author', 'support')->findOrFail($messageId);
            $status = $message->delivery_id ? DB::table('email_delivery_logs')->where('id', $message->delivery_id)->value('status') : 'failed';
            abort_unless(in_array($status, ['failed', 'bounced', 'suppressed'], true), 409, 'Only a confirmed failed notification can be retried.');
            $threads->notify($message, $report);
            AdminAudit::record($request->user()->id, 'retry-notification', 'support-reports', (string) $id);
        }, 3);

        return response()->json(['message' => 'Notification queued; the conversation message was not duplicated.'], 202)->header('Cache-Control', 'private, no-store');
    }
}
