<?php

namespace Tests\Fixtures;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Databases\TenantDataMoving;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * A module whose write meets a move that started after the pipeline's own
 * check (Phase 11): the change must come back as retry_later, not be lost.
 */
class FixtureMovingSync implements SyncableRecords
{
    public function key(): string
    {
        return 'crm.trap';
    }

    public function isMoney(): bool
    {
        return false;
    }

    public function permission(string $action): string
    {
        return 'crm.manage';
    }

    public function rules(): array
    {
        return [];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        throw new TenantDataMoving;
    }

    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        return ['records' => [], 'deleted' => []];
    }
}
