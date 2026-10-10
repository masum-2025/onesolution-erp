<?php

namespace Modules\EducationFees\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Services\Billing;
use Modules\EducationFees\Services\FeeOffice;

/**
 * Every morning: institutions whose rule education_fees.auto_monthly_billing
 * is on, on its day, get this month's monthly bills for every open session
 * the month falls in, made final without the office (op_id per institution,
 * session and month, so a second run that day changes nothing). Off by
 * default: the office runs months itself.
 */
class BillMonthAutomatically extends Command
{
    protected $signature = 'education-fees:auto-bill';

    protected $description = "Bill this month's monthly fees where the institution asked for it.";

    public function handle(TenantDatabases $databases, ModuleResolver $modules, RuleResolver $rules, RuleContextFactory $contexts, AcademicDirectory $academic, FeeOffice $office, Billing $billing): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($modules, $rules, $contexts, $academic, $office, $billing, &$count) {
                $companies = FeeHead::query()->withoutGlobalScope(OrganizationScope::class)->where('frequency', 'monthly')->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $auto = (array) $rules->get('education_fees.auto_monthly_billing', $contexts->forOrganization($company));
                    $today = $office->today($company);
                    if (! $modules->isEnabled('education_fees', $company) || ! ($auto['enabled'] ?? false) || $today->day !== (int) ($auto['day'] ?? 1)) {
                        continue;
                    }
                    $period = $today->format('Y-m');
                    foreach ($academic->sessions($company) as $session) {
                        if ($session['status'] !== 'open' || $session['starts_on'] > $today->toDateString() || $session['ends_on'] < $today->toDateString()) {
                            continue;
                        }
                        try {
                            ['run' => $run] = $billing->createRun($company, $company, [
                                'kind' => 'monthly', 'session_id' => $session['id'], 'period' => $period, 'issue_date' => $today->toDateString(),
                                'op_id' => "auto-{$session['id']}-{$period}",
                            ], null);
                            if ($run->status === 'draft') {
                                $billing->finalizeRun($company, $run, $run->version, null);
                                $count += $run->bills_count;
                            }
                        } catch (FeeException $exception) {
                            // Nobody to bill (or already billed): nothing to do for this session.
                            Log::info('education_fees.auto_bill_skipped', ['company' => $company->getKey(), 'session' => $session['id'], 'code' => $exception->errorCode()]);
                        }
                    }
                }
            });
        }
        $this->info("Billed {$count} student(s).");

        return self::SUCCESS;
    }
}
