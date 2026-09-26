<?php

namespace App\Platform\DataExport\Http;

use App\Http\Controllers\Controller;
use App\Platform\DataExport\Exceptions\ExportException;
use App\Platform\DataExport\Models\DataExport;
use App\Platform\DataExport\Services\DataExportService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A client's full data export: request, follow, download through a
 * short-lived signed link. Allowed in read-only and export-only contexts
 * (a suspended partner's clients), never for support staff.
 */
class DataExportController extends Controller
{
    use FindsVisibleOrganizations;

    private const LINK_MINUTES = 5;

    public function __construct(private DataExportService $exports) {}

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('data.export', $organization);

        $exports = DataExport::query()->where('organization_id', $organization->getKey())->latest()->limit(20)->get();

        return response()->json(['data' => $exports->map(fn (DataExport $export) => $this->present($export))->values()]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('data.export', $organization);

        $export = $this->exports->request($organization, $request->user());

        return response()->json(['data' => $this->present($export->fresh()), 'message' => __('exports.messages.requested')], 202);
    }

    /**
     * A download link valid for a few minutes.
     */
    public function link(string $organization, string $export): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('data.export', $organization);

        $export = DataExport::query()->where('organization_id', $organization->getKey())->whereKey($export)->first()
            ?? throw ExportException::notFound();

        if (! $export->isDownloadable()) {
            throw ExportException::notReady();
        }

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('exports.download', now()->addMinutes(self::LINK_MINUTES), ['export' => $export->getKey()], absolute: false),
            'expires_in' => self::LINK_MINUTES * 60,
        ]]);
    }

    /**
     * The file itself (signed route, see routes/web.php).
     */
    public function download(Request $request, string $export): StreamedResponse
    {
        $export = DataExport::query()->with('organization')->find($export);

        abort_if($export === null || ! $export->isDownloadable(), 404);

        $this->exports->recordDownload($export, $request->user());
        $name = 'export-'.$export->created_at?->format('Ymd-His').'.zip';

        return Storage::disk('local')->download($export->file_path, $name, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DataExport $export): array
    {
        return [
            'id' => $export->getKey(),
            'status' => $export->isDownloadable() || $export->status !== DataExport::READY ? $export->status : DataExport::EXPIRED,
            'size_bytes' => $export->size_bytes,
            'datasets' => $export->summary['datasets'] ?? null,
            'requested_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
