<?php

namespace App\Platform\Audit\Http;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditReport;
use App\Platform\Audit\Exports\AuditExport;
use App\Platform\Audit\Exports\AuditExportService;
use App\Platform\DataExport\Exceptions\ExportException;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * advanced_audit (Phase 9-1): reports over a period and CSV exports of an
 * organization's audit log (it and the units below it). Routes carry
 * module:advanced_audit; permissions advanced_audit.view / .export.
 */
class AdvancedAuditController extends Controller
{
    use FindsVisibleOrganizations;

    private const LINK_MINUTES = 5;

    public function __construct(private AuditExportService $exports) {}

    public function report(AuditReportRequest $request, string $organization, AuditReport $report): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('advanced_audit.view', $organization);

        $filters = $request->filters();
        $to = $filters['to'] ?? CarbonImmutable::now($request->timezone())->startOfDay()->addDay();
        $from = $filters['from'] ?? $to->subDays(30);

        return response()->json(['data' => $report->build($organization, $from, $to, $request->timezone())]);
    }

    public function exports(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('advanced_audit.export', $organization);

        $exports = AuditExport::query()->where('organization_id', $organization->getKey())->latest()->limit(20)->get();

        return response()->json(['data' => $exports->map(fn (AuditExport $export) => $this->present($export))->values()]);
    }

    public function store(AuditExportRequest $request, string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('advanced_audit.export', $organization);

        $export = $this->exports->request($organization, $request->user(), $request->filters());

        return response()->json(['data' => $this->present($export)], 202);
    }

    public function link(string $organization, string $export): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('advanced_audit.export', $organization);

        $export = AuditExport::query()->where('organization_id', $organization->getKey())->whereKey($export)->first()
            ?? throw ExportException::notFound();

        if (! $export->isDownloadable()) {
            throw ExportException::notReady();
        }

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('audit-exports.download', now()->addMinutes(self::LINK_MINUTES), ['export' => $export->getKey()], absolute: false),
            'expires_in' => self::LINK_MINUTES * 60,
        ]]);
    }

    /**
     * The file itself (signed route, see routes/web.php).
     */
    public function download(Request $request, string $export): StreamedResponse
    {
        $export = AuditExport::query()->with('organization')->find($export);

        abort_if($export === null || ! $export->isDownloadable(), 404);

        $this->exports->recordDownload($export, $request->user());

        return Storage::disk('local')->download($export->file_path, 'audit-log-'.$export->created_at?->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AuditExport $export): array
    {
        return [
            'id' => $export->getKey(),
            'status' => $export->status === AuditExport::READY && ! $export->isDownloadable() ? AuditExport::EXPIRED : $export->status,
            'filters' => $export->filters,
            'rows' => $export->rows,
            'size_bytes' => $export->size_bytes,
            'requested_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
