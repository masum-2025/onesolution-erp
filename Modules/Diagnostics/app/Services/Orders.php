<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\Test;

/**
 * Tests ordered by other modules (bound to TestOrders).
 */
class Orders implements TestOrders
{
    public function __construct(private DiagnosticsStore $store, private LabOrders $orders) {}

    public function place(Organization $company, Organization $unit, string $patientId, array $testCodes, string $sourceModule, string $sourceId, User $actor): array
    {
        $ids = $this->store->query(Test::class, $company)->whereIn('code', $testCodes)->where('is_active', true)->pluck('id')->all();
        if (count($ids) !== count(array_unique($testCodes))) {
            throw DiagnosticsException::because('test_unknown');
        }
        $order = $this->orders->place($company, $unit, $patientId, $ids, null, null, null, $actor, ['module' => $sourceModule, 'id' => $sourceId]);

        return ['order_id' => $order->getKey(), 'number' => $order->number];
    }

    public function catalogue(Organization $company): array
    {
        return $this->store->query(Test::class, $company)->where('is_active', true)->orderBy('code')->get()
            ->map(fn (Test $test) => ['id' => $test->getKey(), 'code' => $test->code, 'name' => $test->textIn('name'), 'sample_type' => $test->sample_type])->values()->all();
    }
}
