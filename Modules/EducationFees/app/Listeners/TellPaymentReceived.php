<?php

namespace Modules\EducationFees\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\EducationFees\Events\FeePaid;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Services\FeeOffice;
use Modules\EducationFees\Services\FeeReminders;

/** Money received is told to the family when the institution asks for it (rule education_fees.notify_payment). */
class TellPaymentReceived implements ShouldQueue
{
    public function __construct(private FeeOffice $office, private FeeReminders $reminders, private ModuleResolver $modules) {}

    public function handle(FeePaid $event): void
    {
        $company = Organization::query()->find($event->companyId);
        $receipt = $company === null ? null : $this->office->query(Receipt::class, $company)->find($event->receiptId);
        if ($receipt !== null && $receipt->status === 'valid' && $this->modules->isEnabled('education_fees', $company)) {
            $this->reminders->paymentReceived($company, $receipt);
        }
    }
}
