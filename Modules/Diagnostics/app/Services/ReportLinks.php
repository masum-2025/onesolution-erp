<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\SmsSender;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\ReportLink;
use Modules\Patients\Contracts\PatientDirectory;

/**
 * Links that open one order's verified report without signing in, for the
 * hours the rule diagnostics.report_link_hours allows at the order's branch.
 * The token is random and only its hash is kept; a wrong or unknown token is
 * "not found", an old one "expired". Sent by SMS (the partner's sender, the
 * organization's language) to the patient's mobile when asked or when the
 * rule diagnostics.send_report_sms is on. Making or sending a link counts as
 * giving the report.
 */
class ReportLinks
{
    private const TOKEN_LENGTH = 40;

    public function __construct(
        private DiagnosticsStore $store,
        private LabOrders $orders,
        private PatientDirectory $patients,
        private Notifier $notifier,
        private SmsSender $sms,
        private LinkBuilder $links,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{url: string, expires_at: string, sent_by_sms: bool}
     */
    public function make(Organization $company, Order $order, bool $bySms, ?User $actor): array
    {
        if (! in_array($order->status, ['verified', 'delivered'], true)) {
            throw DiagnosticsException::because('not_verified', 409);
        }
        $patient = $this->patients->find($company, $order->patient_id) ?? throw DiagnosticsException::notFound('patient');
        if ($bySms && ($patient->mobile ?? '') === '') {
            throw DiagnosticsException::because('no_mobile');
        }
        if ($bySms && ! $this->sms->enabled($company->partner)) {
            throw DiagnosticsException::because('sms_off');
        }
        $unit = Organization::query()->find($order->unit_id) ?? $company;
        $hours = max(1, (int) $this->store->rule('report_link_hours', $unit));
        $token = Str::random(self::TOKEN_LENGTH);
        $expires = CarbonImmutable::now()->addHours($hours);

        $link = new ReportLink([
            'organization_id' => $company->getKey(), 'order_id' => $order->getKey(), 'token_hash' => hash('sha256', $token),
            'expires_at' => $expires, 'created_by' => $actor?->getKey(), 'sent_by_sms' => $bySms,
        ]);
        $link->save();
        $path = '/lab-report/'.$company->getKey().'/'.$token;
        $this->orders->deliver($company, $order, $bySms ? 'sms' : 'link', $actor);
        $this->audit->record('diagnostics.report_link_made', $order, new: ['number' => $order->number, 'hours' => $hours, 'sms' => $bySms], actor: $actor, organizationId: $company->getKey());

        if ($bySms) {
            $this->notifier->notifyContacts('diagnostics.report_link', [['name' => $patient->name, 'phone' => $patient->mobile]], fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'number' => $order->number,
                'hours' => (string) $hours,
            ], $company->partner, $company, ['sms'], $path);
        }

        return ['url' => $this->links->to($path, $company->partner, $company), 'expires_at' => $expires->toIso8601String(), 'sent_by_sms' => $bySms];
    }

    /** The automatic text when every test is verified: once per order, and only where there is a mobile and SMS. */
    public function sendOnce(Organization $company, Order $order): void
    {
        if ($this->store->query(ReportLink::class, $company)->where('order_id', $order->getKey())->where('sent_by_sms', true)->exists()) {
            return;
        }
        $patient = $this->patients->find($company, $order->patient_id);
        if ($patient === null || ($patient->mobile ?? '') === '' || ! $this->sms->enabled($company->partner)) {
            return;
        }
        $this->make($company, $order, true, null);
    }

    /** The order a link opens, counting the visit; unknown is 404, expired 403. */
    public function open(Organization $company, string $token): Order
    {
        $link = strlen($token) !== self::TOKEN_LENGTH ? null
            : $this->store->query(ReportLink::class, $company)->where('token_hash', hash('sha256', $token))->first();
        $order = $link === null ? null : $this->store->query(Order::class, $company)->find($link->order_id);
        if ($order === null) {
            throw DiagnosticsException::notFound('report');
        }
        if ($link->expires_at->isPast()) {
            throw DiagnosticsException::because('link_expired', 403);
        }
        $this->store->query(ReportLink::class, $company)->whereKey($link->getKey())->update([
            'opened_count' => $link->opened_count + 1, 'last_opened_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);

        return $order;
    }
}
