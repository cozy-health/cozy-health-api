<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderClientLink;
use App\Models\ProviderReport;
use App\Models\User;
use App\Services\Provider\ProviderAudit;
use App\Services\Provider\ProviderClientAccess;
use App\Services\Provider\ProviderReportService;
use App\Services\Provider\EncryptedReportStorage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ProviderReportController extends Controller
{
    public function __construct(private ProviderReportService $reports) {}

    public function index(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $links = ProviderClientLink::active()->where('provider_id', $request->user()->id)->whereHas('user')->lockForUpdate()->get()->keyBy('id');
            $reports = ProviderReport::where('provider_id', $request->user()->id)->whereIn('link_id', $links->keys())
                ->orderByDesc('generated_at')->orderByDesc('id')->paginate(ProviderClientAccess::pagination($request));
            foreach ($reports as $report) {
                ProviderAudit::record($request, $links[$report->link_id], 'list', 'report', (string) $report->id);
            }

            return $reports;
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'type' => ['sometimes', 'in:monthly'],
            'from' => ['required_with:to', 'date_format:Y-m-d'], 'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $user = User::findOrFail($data['user_id']);
        $timezone = $request->user()->timezone ?: 'UTC';
        $report = $this->reports->generate($request->user(), $user, $data['type'] ?? 'monthly',
            isset($data['from']) ? Carbon::createFromFormat('!Y-m-d', $data['from'], $timezone) : null,
            isset($data['to']) ? Carbon::createFromFormat('!Y-m-d', $data['to'], $timezone) : null);

        return response()->json(['report' => $report], 201);
    }

    public function show(Request $request, string $reportId)
    {
        return DB::transaction(function () use ($request, $reportId) {
            [$report, $link] = $this->find($request, $reportId);
            ProviderAudit::record($request, $link, 'view', 'report', (string) $report->id);

            return response()->json(['report' => $report]);
        });
    }

    public function download(Request $request, string $reportId)
    {
        return DB::transaction(function () use ($request, $reportId) {
            [$report, $link] = $this->find($request, $reportId);
            abort_unless(Storage::disk('local')->exists($report->file_path), 404);
            $expires = now()->addHour();
            // Sign only the path/query so TLS termination cannot invalidate the signature.
            $url = URL::temporarySignedRoute('provider.reports.file', $expires, ['reportId' => $report->id], absolute: false);
            ProviderAudit::record($request, $link, 'view', 'report', (string) $report->id);

            return response()->json(['download_url' => $url, 'expires_at' => $expires->toIso8601String()])->header('Cache-Control', 'private, no-store');
        });
    }

    public function file(Request $request, string $reportId)
    {
        return DB::transaction(function () use ($request, $reportId) {
            [$report, $link] = $this->find($request, $reportId);
            $disk = Storage::disk('local');
            abort_unless($disk->exists($report->file_path), 404);
            // Read bytes while the consent/link lock is held; do not defer reading to a stream.
            $bytes = app(EncryptedReportStorage::class)->decrypt($disk->get($report->file_path));
            ProviderAudit::record($request, $link, 'download', 'report', (string) $report->id);

            return response($bytes)->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="provider-report-'.$report->id.'.pdf"')
                ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        });
    }

    private function find(Request $request, string $reportId): array
    {
        $report = ProviderReport::where('provider_id', $request->user()->id)->findOrFail($reportId);
        $link = ProviderClientAccess::link($request, (string) $report->user_id);
        abort_unless($this->reports->accessible($report, $link), 404);

        return [$report, $link];
    }
}
