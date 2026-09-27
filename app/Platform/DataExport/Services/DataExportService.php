<?php

namespace App\Platform\DataExport\Services;

use App\Platform\DataExport\Events\DataExportReady;
use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\DataExport\Exceptions\ExportException;
use App\Platform\DataExport\Jobs\BuildDataExport;
use App\Platform\DataExport\Models\DataExport;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Client data exports: requested by a client admin (also while the partner
 * is suspended), built in the background, downloadable for a limited time,
 * then deleted. One export at a time per organization.
 */
class DataExportService
{
    public function __construct(
        private ExportBuilder $builder,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function request(Organization $organization, User $actor): DataExport
    {
        $export = DB::transaction(function () use ($organization, $actor) {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            $running = DataExport::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('status', [DataExport::QUEUED, DataExport::RUNNING])
                ->exists();

            if ($running) {
                throw ExportException::alreadyRunning();
            }

            $export = DataExport::create([
                'organization_id' => $organization->getKey(),
                'requested_by' => $actor->getKey(),
                'status' => DataExport::QUEUED,
            ]);

            $this->audit->record(action: 'data.export_requested', target: $export, actor: $actor, organizationId: $organization->getKey(), partnerId: $organization->partner_id);

            return $export;
        });

        BuildDataExport::dispatch($export->getKey())->afterCommit();

        return $export;
    }

    /**
     * Build the file (run by the queued job).
     */
    public function build(DataExport $export): void
    {
        $export->forceFill(['status' => DataExport::RUNNING])->save();
        $path = "exports/{$export->organization_id}/{$export->getKey()}.zip";
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));

        try {
            $summary = $this->builder->build($export->organization, $disk->path($path));
            $days = (int) $this->rules->get('exports.retention_days', $this->contexts->forOrganization($export->organization));

            $export->forceFill([
                'status' => DataExport::READY,
                'file_path' => $path,
                'size_bytes' => $disk->size($path),
                'summary' => $summary,
                'completed_at' => now(),
                'expires_at' => now()->addDays($days),
            ])->save();

            DataExportReady::dispatch($export);
        } catch (Throwable $exception) {
            $disk->delete($path);
            $export->forceFill(['status' => DataExport::FAILED, 'completed_at' => now()])->save();

            report($exception);
        }
    }

    public function recordDownload(DataExport $export, ?User $actor): void
    {
        $this->audit->record(
            action: 'data.export_downloaded',
            target: $export,
            actor: $actor,
            organizationId: $export->organization_id,
            partnerId: $export->organization->partner_id,
        );
    }

    /**
     * Deletes every export file of an organization now (e.g. its owner
     * deleted their account, Phase 5C-3). The records stay, marked expired.
     */
    public function discardFor(Organization $organization): int
    {
        $exports = DataExport::query()->where('organization_id', $organization->getKey())->whereNotNull('file_path')->get();

        foreach ($exports as $export) {
            Storage::disk('local')->delete($export->file_path);
            $export->forceFill(['status' => DataExport::EXPIRED, 'file_path' => null])->save();
        }

        return $exports->count();
    }

    /**
     * Delete files past their retention (scheduled daily).
     */
    public function pruneExpired(): int
    {
        $count = 0;

        DataExport::query()
            ->where('status', DataExport::READY)
            ->where('expires_at', '<=', now())
            ->each(function (DataExport $export) use (&$count) {
                if ($export->file_path !== null) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->forceFill(['status' => DataExport::EXPIRED, 'file_path' => null])->save();
                $count++;
            });

        return $count;
    }
}
