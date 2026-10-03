<?php

namespace Modules\Accounting\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Reports;

/**
 * What the Accounting widgets read: the books of the current organization
 * when it keeps them (a company or personal workspace that is set up);
 * groups, branches and departments get empty widgets.
 */
trait ReadsBooks
{
    private function company(CurrentContext $context): ?Organization
    {
        $organization = $context->organization();
        if (! in_array($organization->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return null;
        }

        return app(Books::class)->isSetUp($organization) ? $organization : null;
    }

    private function today(Organization $company): CarbonImmutable
    {
        return app(Books::class)->today($company);
    }

    /**
     * Income or expenses between two days, in that type's own direction.
     */
    private function amountOf(Organization $company, AccountType $type, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $ids = app(Books::class)->query(Account::class, $company)->where('type', $type->value)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($ids === []) {
            return 0;
        }

        $amount = 0;
        foreach (app(Reports::class)->totals($company, $from->toDateString(), $to->toDateString(), null, $ids) as $total) {
            $amount += $type->balance($total['debit'], $total['credit']);
        }

        return $amount;
    }
}
