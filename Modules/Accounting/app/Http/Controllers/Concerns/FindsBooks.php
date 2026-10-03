<?php

namespace Modules\Accounting\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Services\Books;

/**
 * The company in the address (it must keep books itself) and its records;
 * anything of another company is the same 404 (no guessing).
 */
trait FindsBooks
{
    use FindsVisibleOrganizations;

    protected function company(string $id): Organization
    {
        return app(Books::class)->assertKeepsBooks($this->findVisible($id));
    }

    protected function accountIn(Organization $company, string $id): Account
    {
        return app(Books::class)->query(Account::class, $company)->whereKey($id)->first() ?? throw AccountingException::accountNotFound();
    }

    protected function journalIn(Organization $company, string $id): Journal
    {
        return app(Books::class)->query(Journal::class, $company)->whereKey($id)->first() ?? throw AccountingException::journalNotFound();
    }

    protected function periodIn(Organization $company, string $id): Period
    {
        return app(Books::class)->query(Period::class, $company)->whereKey($id)->first() ?? throw AccountingException::periodNotFound();
    }
}
