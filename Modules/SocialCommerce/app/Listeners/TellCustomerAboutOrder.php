<?php

namespace Modules\SocialCommerce\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Modules\OnlineOrders\Events\OrderCancelled;
use Modules\OnlineOrders\Events\OrderConfirmed;
use Modules\OnlineOrders\Events\OrderDelivered;
use Modules\OnlineOrders\Events\OrderShipped;
use Modules\OnlineOrders\Services\Orders;
use Modules\SocialCommerce\Services\Conversations;

/**
 * An order taken in Messenger moved on in Online orders: the customer is
 * told in their chat (confirmed, on its way with the courier, delivered,
 * cancelled), where social_commerce.notify_order_status is on.
 */
class TellCustomerAboutOrder
{
    public function __construct(private Conversations $conversations, private ModuleResolver $modules, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function handle(OrderConfirmed|OrderShipped|OrderDelivered|OrderCancelled $event): void
    {
        if ($event->channel !== 'facebook' || $event->channelRef === null) {
            return;
        }
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('social_commerce', $company)
            || $this->rules->get('social_commerce.notify_order_status', $this->contexts->forOrganization($company)) !== true) {
            return;
        }
        $order = app(Orders::class)->brief($company, $event->orderId);
        if ($order === null) {
            return;
        }
        $intent = match (true) {
            $event instanceof OrderConfirmed => 'status_confirmed',
            $event instanceof OrderShipped => 'status_shipped',
            $event instanceof OrderDelivered => 'status_delivered',
            default => 'status_cancelled',
        };
        $this->conversations->tellCustomer($company, $event->channelRef, $intent, fn (string $locale) => [
            'number' => $order['number'],
            'total' => MoneyText::shown($order['total_minor'], $order['currency'], $locale),
            'courier' => (string) ($order['courier'] ?? ''),
            'tracking' => (string) ($order['tracking'] ?? ''),
        ]);
    }
}
