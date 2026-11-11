<?php

namespace Modules\Diagnostics\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Diagnostics\Events\ReportReady;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Services\DiagnosticsStore;
use Modules\Diagnostics\Services\ReportLinks;

/**
 * When the rule diagnostics.send_report_sms is on at the order's branch, the
 * patient gets a text with a time-limited link as soon as every test is
 * verified. Queued: verifying never waits for the SMS provider. Off (the
 * default), the desk sends the link by hand.
 */
class SmsWhenReportReady implements ShouldQueue
{
    public function __construct(private DiagnosticsStore $store, private ReportLinks $links, private ModuleResolver $modules) {}

    public function handle(ReportReady $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('diagnostics', $company)) {
            return;
        }
        $order = $this->store->query(Order::class, $company)->find($event->orderId);
        $unit = $order === null ? null : Organization::query()->find($order->unit_id);
        if ($unit === null || ! (bool) $this->store->rule('send_report_sms', $unit)) {
            return;
        }
        $this->links->sendOnce($company, $order);
    }
}
