<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Http\Controllers\Concerns\FindsPlace;
use Modules\Compliance\Http\Requests\ImportRequest;
use Modules\Compliance\Services\Importer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The register from an Excel or CSV file at the unit in the address:
 * checked first (dry_run), then imported. Importing sets official dates,
 * so it needs both compliance.manage and compliance.set_expiry.
 */
class ImportController extends Controller
{
    use FindsPlace;

    public function __construct(private Importer $importer, private AuditLogger $audit) {}

    public function store(ImportRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.manage');
        Gate::authorize('compliance.set_expiry', $unit);
        $file = $request->file('file');

        if ($request->boolean('dry_run', true)) {
            return response()->json(['data' => [...$this->importer->check($unit, $file), 'max_rows' => $this->importer->maxRows($unit)]]);
        }
        $done = $this->importer->import($unit, $file, $request->boolean('skip_invalid'), $request->user());
        $this->audit->record('compliance.imported', null, new: [...$done, 'file' => mb_substr($file->getClientOriginalName(), 0, 120)],
            actor: $request->user(), organizationId: $unit->getKey());

        return response()->json(['data' => $done]);
    }

    /** An empty Excel file with the columns and one example row. */
    public function template(string $organization): BinaryFileResponse
    {
        $this->unit($organization, 'compliance.view');
        $path = tempnam(sys_get_temp_dir(), 'cmp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(Importer::COLUMNS));
        $writer->addRow(Row::fromValues(['Main Factory', 'BSCI', 'Apex Compliance Services', 'renewal', '2026-03-15', '2027-03-14', 'B', 'BSCI-123456']));
        $writer->close();

        return response()->download($path, 'audit-register.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
