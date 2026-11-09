<?php

namespace Modules\OnlineOrders\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\OnlineOrders\Couriers\CourierDrivers;
use Modules\OnlineOrders\Couriers\CourierFailed;
use Modules\OnlineOrders\Couriers\Parcel;
use Modules\OnlineOrders\Exceptions\OnlineOrdersException;
use Modules\OnlineOrders\Models\Courier;
use Modules\OnlineOrders\Models\Order;

/**
 * SC-5b: a company's courier accounts and the parcels booked with them.
 *
 * Booking asks the courier first (outside any lock: it is another company's
 * server), then ships the order with the courier's id and tracking code, so
 * a refused booking leaves the order as it was. The courier's word about a
 * parcel comes from its webhook (the account's own token) or from asking it
 * (refresh) and goes to Orders::courierSays.
 */
class Couriers
{
    public function __construct(
        private OrderBook $book,
        private Orders $orders,
        private CourierDrivers $drivers,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** @return list<string> Couriers the company may use: the rule's list, for its country, that the platform can talk to. */
    public function allowed(Organization $company): array
    {
        $country = $this->book->country($company);
        $listed = (array) $this->rules->get('online_orders.couriers', $this->contexts->forOrganization($company));

        return array_values(array_filter($listed, fn ($key) => is_string($key) && $this->drivers->has($key)
            && in_array($country, $this->drivers->get($key)->countries(), true)));
    }

    /** @return Collection<int, Courier> */
    public function accounts(Organization $company): Collection
    {
        return $this->book->query(Courier::class, $company)->orderBy('name')->get();
    }

    public function find(Organization $company, string $id): Courier
    {
        return (Str::isUlid($id) ? $this->book->query(Courier::class, $company)->whereKey($id)->first() : null)
            ?? throw OnlineOrdersException::notFound('courier');
    }

    /**
     * A new account. The webhook token is given back once (only its hash is kept).
     *
     * @param  array{driver: string, name: string, credentials: array<string, string>}  $data
     * @return array{courier: Courier, token: string}
     */
    public function add(Organization $company, array $data, User $actor): array
    {
        if (! in_array($data['driver'], $this->allowed($company), true)) {
            throw OnlineOrdersException::courierNotAllowed();
        }
        $token = Str::random(48);
        $courier = (new Courier)->forceFill([
            'organization_id' => $company->getKey(),
            'driver' => $data['driver'],
            'name' => $data['name'],
            'credentials' => $this->credentials($data['driver'], $data['credentials']),
            'webhook_token_hash' => hash('sha256', $token),
            'is_active' => true,
            'version' => 1,
        ]);
        $courier->save();
        $this->audit->record('online_orders.courier_added', $courier, new: ['driver' => $courier->driver, 'name' => $courier->name],
            actor: $actor, organizationId: $company->getKey());

        return ['courier' => $courier, 'token' => $token];
    }

    /**
     * A name, new keys (all of them), switched on or off, or a new webhook token (given back once).
     *
     * @param  array{name?: string, credentials?: array<string, string>, is_active?: bool, new_token?: bool}  $data
     * @return array{courier: Courier, token: ?string}
     */
    public function change(Organization $company, string $id, int $baseVersion, array $data, User $actor): array
    {
        $token = ($data['new_token'] ?? false) ? Str::random(48) : null;
        $courier = $this->book->transaction($company, function () use ($company, $id, $baseVersion, $data, $token) {
            $row = $this->book->query(Courier::class, $company)->whereKey($this->find($company, $id)->getKey())->lockForUpdate()->firstOrFail();
            if ($row->version !== $baseVersion) {
                throw OnlineOrdersException::versionConflict(['version' => $row->version]);
            }
            $row->forceFill([
                ...(isset($data['name']) ? ['name' => $data['name']] : []),
                ...(isset($data['credentials']) ? ['credentials' => $this->credentials($row->driver, $data['credentials'])] : []),
                ...(isset($data['is_active']) ? ['is_active' => $data['is_active']] : []),
                ...($token !== null ? ['webhook_token_hash' => hash('sha256', $token)] : []),
                'version' => $row->version + 1,
            ])->save();

            return $row;
        });
        // Which parts changed, never the keys themselves.
        $this->audit->record('online_orders.courier_changed', $courier, new: ['name' => $courier->name, 'is_active' => $courier->is_active,
            'keys_changed' => isset($data['credentials']), 'token_changed' => $token !== null], actor: $actor, organizationId: $company->getKey());

        return ['courier' => $courier, 'token' => $token];
    }

    /** Book the order with the courier and send it out (the cash to collect is what is left to pay). */
    public function book(Organization $company, Order $order, int $baseVersion, string $courierId, User $actor): Order
    {
        $courier = $this->usable($company, $courierId);
        $this->orders->shippable($order);
        if ($order->version !== $baseVersion) {
            throw OnlineOrdersException::versionConflict(['version' => $order->version, 'status' => $order->status]);
        }

        try {
            $booking = $this->drivers->get($courier->driver)->book($courier->credentials, new Parcel($order->number, $order->customer_name,
                $order->customer_phone, $order->address, $order->dueMinor(), $order->currency_code));
        } catch (CourierFailed $failed) {
            throw OnlineOrdersException::courierFailed($failed->reason, $failed->detail);
        }

        $shipped = $this->orders->ship($company, $order, $baseVersion, ['courier' => $courier->name, 'tracking' => $booking->tracking,
            'courier_id' => $courier->getKey(), 'courier_ref' => $booking->consignment, 'courier_status' => $booking->status], $actor);
        $this->audit->record('online_orders.courier_booked', $shipped, new: ['number' => $shipped->number, 'courier' => $courier->name,
            'consignment' => $booking->consignment, 'cod_minor' => $order->dueMinor()], actor: $actor, organizationId: $company->getKey());

        return $shipped;
    }

    /** Ask the courier where a booked parcel stands now. */
    public function refresh(Organization $company, Order $order, User $actor): Order
    {
        if ($order->courier_id === null || $order->courier_ref === null) {
            throw OnlineOrdersException::notBooked();
        }
        $courier = $this->find($company, $order->courier_id);
        try {
            $status = $this->drivers->get($courier->driver)->status($courier->credentials, $order->courier_ref);
        } catch (CourierFailed $failed) {
            throw OnlineOrdersException::courierFailed($failed->reason, $failed->detail);
        }

        return $this->orders->courierSays($company, $order->getKey(), $status, null, $actor);
    }

    /**
     * A notice the courier sent to the account's webhook. False when the token is not the account's (or it is
     * off); true otherwise, also for notices about parcels we do not know (answered, nothing changed).
     *
     * @param  array<string, mixed>  $payload
     */
    public function notice(Organization $company, string $courierId, string $token, array $payload): bool
    {
        $courier = Str::isUlid($courierId) ? $this->book->query(Courier::class, $company)->whereKey($courierId)->first() : null;
        if ($courier === null || ! $courier->is_active || $token === '' || ! hash_equals($courier->webhook_token_hash, hash('sha256', $token))) {
            return false;
        }
        $said = $this->drivers->get($courier->driver)->readNotice($payload);
        $order = $said === null ? null : $this->book->query(Order::class, $company)
            ->where('courier_id', $courier->getKey())->where('courier_ref', $said['consignment'])->first();
        if ($order !== null) {
            $this->orders->courierSays($company, $order->getKey(), $said['status'], $said['note'], null);
        }

        return true;
    }

    // ---------------------------------------------------------------- inside

    private function usable(Organization $company, string $id): Courier
    {
        $courier = $this->find($company, $id);
        if (! $courier->is_active) {
            throw OnlineOrdersException::courierOff();
        }
        if (! in_array($courier->driver, $this->allowed($company), true)) {
            throw OnlineOrdersException::courierNotAllowed();
        }

        return $courier;
    }

    /**
     * Exactly the driver's fields, each filled.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, string>
     */
    private function credentials(string $driver, array $given): array
    {
        $fields = $this->drivers->get($driver)->credentialFields();
        $missing = array_filter($fields, fn (string $field) => ! is_string($given[$field] ?? null) || trim($given[$field]) === '');
        if ($missing !== [] || array_diff(array_keys($given), $fields) !== []) {
            throw ValidationException::withMessages(['credentials' => __('online_orders::orders.errors.courier_keys', ['fields' => implode(', ', $fields)])]);
        }

        return array_map(fn (string $field) => trim($given[$field]), array_combine($fields, $fields));
    }
}
