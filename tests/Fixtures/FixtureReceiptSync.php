<?php

namespace Tests\Fixtures;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * How a module would let people record cash received offline: money, so
 * append-only (the pipeline refuses changes and deletions).
 */
class FixtureReceiptSync implements SyncableRecords
{
    public function key(): string
    {
        return 'crm.receipt';
    }

    public function isMoney(): bool
    {
        return true;
    }

    public function permission(string $action): string
    {
        return 'crm.manage';
    }

    public function rules(): array
    {
        return ['offline_mode.allow_offline_payments'];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        $data = Validator::make($operation->data, [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'size:3'],
        ])->validate();

        $receipt = FixtureCashReceipt::query()->create([...$data, 'version' => 1]);

        return SyncResult::applied($receipt->getKey(), 1);
    }

    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        $receipts = FixtureCashReceipt::query()->when($since !== null, fn ($query) => $query->where('updated_at', '>', $since))->limit($limit)->get();

        return ['records' => $receipts->map(fn (FixtureCashReceipt $receipt) => ['id' => $receipt->getKey(), 'amount_minor' => $receipt->amount_minor])->values()->all(), 'deleted' => []];
    }
}
