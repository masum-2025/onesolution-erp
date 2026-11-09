<?php

namespace Modules\OnlineOrders\Payments;

use App\Models\User;
use App\Platform\Payments\CollectionDue;
use App\Platform\Payments\Contracts\CollectableProvider;
use App\Platform\Payments\Contracts\GuestCollectable;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCustomer;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\URL;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Services\OrderBook;
use Modules\OnlineOrders\Services\Orders;

/**
 * An online order paid by its customer through a signed link (SC-5a), into
 * the company's own merchant account. Only as a guest: the link sent to the
 * customer is the proof; the amount is Orders::onlineDue's.
 */
class OrderCollectable implements CollectableProvider, GuestCollectable
{
    public const KIND = 'online_orders.order';

    public function __construct(private Orders $orders, private OrderBook $book) {}

    public function key(): string
    {
        return self::KIND;
    }

    public function label(?string $locale = null): string
    {
        return __('online_orders::orders.pay.kind', [], $locale);
    }

    /** Signed-in people do not pay orders here (staff record what they take). */
    public function due(Organization $organization, string $id, User $payer): ?CollectionDue
    {
        return null;
    }

    public function dueForGuest(Organization $organization, string $id): ?CollectionDue
    {
        $due = $this->orders->onlineDue($organization, $id);

        return $due === null ? null : new CollectionDue($due['amount_minor'], $due['currency']);
    }

    public function guestCustomer(Organization $organization, string $id): PaymentCustomer
    {
        $order = $this->book->query(Order::class, $organization)->whereKey($id)->firstOrFail();

        return new PaymentCustomer(
            name: $order->customer_name,
            email: null,
            phone: $order->customer_phone,
            countryCode: $organization->country_code ?? (string) config('tenancy.defaults.country_code'),
        );
    }

    public function paid(Payment $payment): void
    {
        $company = Organization::query()->findOrFail($payment->organization_id);
        $this->orders->payOnline($company, (string) $payment->subject_id, (int) $payment->amount_minor, (string) ($payment->gateway_ref ?? $payment->getKey()), 'payment-'.$payment->getKey());
    }

    /** Back to the same signed page, which shows how the payment went. */
    public function returnPath(Payment $payment): string
    {
        return URL::temporarySignedRoute('online_orders.pay', now()->addHour(), ['organization' => $payment->organization_id, 'order' => $payment->subject_id, 'payment' => $payment->getKey()], absolute: false);
    }
}
