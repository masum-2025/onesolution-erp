<?php

namespace Modules\OnlineOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OnlineOrders\Couriers\CourierDrivers;
use Modules\OnlineOrders\Models\Courier;
use Modules\OnlineOrders\Http\Requests\CourierRequest;
use Modules\OnlineOrders\Services\Couriers;
use Modules\OnlineOrders\Services\OrderBook;

/**
 * SC-5b: the company's courier accounts (online_orders.couriers at the
 * company). Keys are written, never read back; the webhook address and its
 * token are shown once, when made.
 */
class CourierController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private OrderBook $book, private Couriers $couriers, private CourierDrivers $drivers) {}

    /** The accounts, and the couriers the company may add (with the keys each one needs). */
    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);

        return response()->json(['data' => $this->couriers->accounts($company)->map(fn (Courier $courier) => $this->shown($company, $courier))->values()->all(),
            'meta' => ['drivers' => array_map(fn (string $key) => ['key' => $key, 'fields' => $this->drivers->get($key)->credentialFields()], $this->couriers->allowed($company))]]);
    }

    public function store(CourierRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        $made = $this->couriers->add($company, $request->validated(), $request->user());

        return response()->json(['data' => [...$this->shown($company, $made['courier']), 'webhook_token' => $made['token']]], 201);
    }

    public function update(CourierRequest $request, string $organization, string $courier): JsonResponse
    {
        $company = $this->company($organization);
        $data = $request->validated();
        $changed = $this->couriers->change($company, $courier, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => [...$this->shown($company, $changed['courier']), 'webhook_token' => $changed['token']]]);
    }

    // ---------------------------------------------------------------- inside

    /** The company in the address (only there: accounts are the company's), for one who may manage couriers. */
    private function company(string $organization): Organization
    {
        $unit = $this->findVisible($organization);
        $company = $this->book->companyOf($unit);
        abort_unless($company->is($unit), 404);
        Gate::authorize('online_orders.couriers', $company);

        return $company;
    }

    /** @return array<string, mixed> */
    private function shown(Organization $company, Courier $courier): array
    {
        return [
            'id' => $courier->getKey(), 'driver' => $courier->driver, 'name' => $courier->name, 'is_active' => $courier->is_active, 'version' => $courier->version,
            'webhook_url' => url("/api/couriers/{$company->getKey()}/{$courier->getKey()}/webhook"),
            'created_at' => $courier->created_at?->toIso8601String(), 'updated_at' => $courier->updated_at?->toIso8601String(),
        ];
    }
}
