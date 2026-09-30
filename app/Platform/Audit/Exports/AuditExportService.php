<?php

namespace App\Platform\Audit\Exports;

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Audit\AuditQuery;
use App\Platform\DataExport\Exceptions\ExportException;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * CSV exports of an organization's audit log (advanced_audit, Phase 9-1):
 * one at a time per organization, built in the background, downloadable for
 * the data export retention (rule exports.retention_days), then deleted.
 */
class AuditExportService
{
    public const COLUMNS = [
        'created_at_utc', 'organization', 'actor', 'actor_id', 'action', 'label', 'target_type', 'target_id',
        'reason', 'ip_address', 'device_id', 'session_id', 'api_key_id', 'old_values', 'new_values',
    ];

    public function __construct(
        private AuditQuery $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $logger,
    ) {}

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, action?: ?string, actor?: ?string}  $filters
     */
    public function request(Organization $organization, User $actor, array $filters): AuditExport
    {
        $export = DB::transaction(function () use ($organization, $actor, $filters) {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            $running = AuditExport::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('status', [AuditExport::QUEUED, AuditExport::RUNNING])
                ->exists();

            if ($running) {
                throw ExportException::alreadyRunning();
            }

            $export = AuditExport::create([
                'organization_id' => $organization->getKey(),
                'requested_by' => $actor->getKey(),
                'status' => AuditExport::QUEUED,
                'filters' => [
                    'from' => $filters['from']->toIso8601String(),
                    'to' => $filters['to']->toIso8601String(),
                    // The days as the person chose them ("to" above is the start of the next day).
                    'from_date' => $filters['from']->toDateString(),
                    'to_date' => $filters['to']->subDay()->toDateString(),
                    'action' => $filters['action'] ?? null,
                    'actor' => $filters['actor'] ?? null,
                ],
            ]);

            $this->logger->record(action: 'audit.export_requested', target: $export, new: $export->filters, actor: $actor, organizationId: $organization->getKey(), partnerId: $organization->partner_id);

            return $export;
        });

        BuildAuditExport::dispatch($export->getKey(), $organization->getKey())->afterCommit();

        return $export;
    }

    public function build(AuditExport $export): void
    {
        $export->forceFill(['status' => AuditExport::RUNNING])->save();
        $path = "audit-exports/{$export->organization_id}/{$export->getKey()}.csv";
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));

        try {
            $rows = $this->write($export, $disk->path($path));
            $days = (int) $this->rules->get('exports.retention_days', $this->contexts->forOrganization($export->organization));

            $export->forceFill([
                'status' => AuditExport::READY,
                'file_path' => $path,
                'size_bytes' => $disk->size($path),
                'rows' => $rows,
                'completed_at' => now(),
                'expires_at' => now()->addDays($days),
            ])->save();
        } catch (Throwable $exception) {
            $disk->delete($path);
            $export->forceFill(['status' => AuditExport::FAILED, 'completed_at' => now()])->save();

            report($exception);
        }
    }

    public function recordDownload(AuditExport $export, ?User $actor): void
    {
        $this->logger->record(action: 'audit.export_downloaded', target: $export, actor: $actor, organizationId: $export->organization_id, partnerId: $export->organization->partner_id);
    }

    /**
     * Delete files past their retention, and give up on builds that never finished.
     */
    public function prune(): int
    {
        $count = 0;

        AuditExport::query()
            ->where(fn ($query) => $query
                ->where(fn ($ready) => $ready->where('status', AuditExport::READY)->where('expires_at', '<=', now()))
                ->orWhere(fn ($stuck) => $stuck->whereIn('status', [AuditExport::QUEUED, AuditExport::RUNNING])->where('created_at', '<=', now()->subDay())))
            ->each(function (AuditExport $export) use (&$count) {
                if ($export->file_path !== null) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->forceFill([
                    'status' => $export->status === AuditExport::READY ? AuditExport::EXPIRED : AuditExport::FAILED,
                    'file_path' => null,
                ])->save();
                $count++;
            });

        return $count;
    }

    private function write(AuditExport $export, string $file): int
    {
        $filters = $export->filters;
        $query = $this->audit->forReporting($export->organization, [
            'from' => CarbonImmutable::parse($filters['from']),
            'to' => CarbonImmutable::parse($filters['to']),
            'action' => $filters['action'] ?? null,
            'actor' => $filters['actor'] ?? null,
        ])->orderBy('created_at')->orderBy('id');

        $handle = fopen($file, 'wb');
        // UTF-8 byte order mark: spreadsheet programs then show Bangla correctly.
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::COLUMNS, escape: '');

        $rows = 0;
        $max = (int) config('audit.export_max_rows');
        $people = [];
        $units = [];

        try {
            foreach ($query->lazy(1000) as $entry) {
                /** @var AuditLog $entry */
                // lazy() pages on its own, so the row cap is counted here.
                if ($rows >= $max) {
                    break;
                }

                $people[$entry->actor_user_id] ??= $entry->actor_user_id === null ? '' : (string) User::query()->whereKey($entry->actor_user_id)->value('name');
                $units[$entry->organization_id] ??= (string) Organization::query()->find($entry->organization_id)?->displayName();

                fputcsv($handle, array_map($this->cell(...), [
                    $entry->created_at?->utc()->toIso8601String(),
                    $units[$entry->organization_id],
                    $people[$entry->actor_user_id] ?? '',
                    $entry->actor_user_id,
                    $entry->action,
                    $this->audit->label($entry->action),
                    $entry->target_type,
                    $entry->target_id,
                    $entry->reason,
                    $entry->ip_address,
                    $entry->device_id,
                    $entry->session_id,
                    $entry->api_key_id,
                    $entry->old_values === null ? '' : json_encode($entry->old_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $entry->new_values === null ? '' : json_encode($entry->new_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]), escape: '');
                $rows++;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * A cell a spreadsheet will not run as a formula (CSV injection).
     */
    private function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
