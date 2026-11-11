<?php

namespace Modules\PatientBilling\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Modules\PatientBilling\Contracts\PatientCharges;
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\Bill;
use Modules\PatientBilling\Models\BillLine;
use Modules\PatientBilling\Models\Service;

/**
 * Charges from other departments onto the patient's open bill (bound to PatientCharges).
 */
class Charges implements PatientCharges
{
    public function __construct(private PatientBillingStore $store, private Bills $bills) {}

    public function add(Organization $company, Organization $unit, string $patientId, string $sourceModule, string $sourceType, string $sourceId, string $serviceCode, int $quantityMilli, User $actor): array
    {
        $source = ['module' => $sourceModule, 'type' => $sourceType, 'id' => $sourceId];
        $done = $this->store->query(BillLine::class, $company)->where('source_module', $sourceModule)->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
        if ($done !== null) {
            return ['bill_id' => $done->bill_id, 'line_id' => $done->getKey()];
        }
        $service = $this->store->query(Service::class, $company)->where('code', $serviceCode)->first() ?? throw PatientBillingException::notFound('service');
        $bill = $this->bills->openFor($company, $unit, $patientId, $actor);
        $line = $this->bills->addLine($company, $bill, $service->getKey(), $quantityMilli, $actor, $source);

        return ['bill_id' => $line->bill_id, 'line_id' => $line->getKey()];
    }

    public function services(Organization $company): array
    {
        return $this->store->query(Service::class, $company)->where('is_active', true)->orderBy('code')->get()
            ->mapWithKeys(fn (Service $service) => [$service->code => ['name' => $service->textIn('name'), 'price_minor' => $service->price_minor]])->all();
    }

    public function dueOn(Organization $company, string $billId): ?int
    {
        $bill = $this->store->query(Bill::class, $company)->whereKey($billId)->first();

        return $bill === null || $bill->status === Bill::VOID ? null : $bill->dueMinor();
    }

    public function chargesOn(Organization $company, string $billId, string $sourceModule): ?array
    {
        $bill = $this->store->query(Bill::class, $company)->whereKey($billId)->first();
        if ($bill === null || $bill->status === Bill::VOID) {
            return null;
        }
        $lines = $this->store->query(BillLine::class, $company)->where('bill_id', $billId)->where('source_module', $sourceModule)->whereNull('voided_at')->get();

        return [
            'status' => $bill->status,
            'currency' => $bill->currency,
            'lines' => $lines->mapWithKeys(fn (BillLine $line) => [(string) $line->source_id => $line->total_minor - $line->tax_minor])->all(),
        ];
    }
}
