<?php

namespace App\Platform\Audit\Exports;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CSV export of one organization's audit log (advanced_audit, Phase 9-1).
 * Written only by AuditExportService and the BuildAuditExport job.
 */
#[Table('audit_exports')]
#[Fillable(['organization_id', 'requested_by', 'status', 'filters', 'file_path', 'size_bytes', 'rows', 'completed_at', 'expires_at'])]
class AuditExport extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'size_bytes' => 'integer',
            'rows' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->file_path !== null && $this->expires_at?->isFuture() === true;
    }
}
