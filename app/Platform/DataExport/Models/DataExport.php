<?php

namespace App\Platform\DataExport\Models;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A full data export of one organization (and the units below it). Written
 * only by DataExportService and the BuildDataExport job.
 */
#[Table('data_exports')]
#[Fillable(['organization_id', 'requested_by', 'status', 'file_path', 'size_bytes', 'summary', 'completed_at', 'expires_at'])]
class DataExport extends Model
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
            'summary' => 'array',
            'size_bytes' => 'integer',
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
