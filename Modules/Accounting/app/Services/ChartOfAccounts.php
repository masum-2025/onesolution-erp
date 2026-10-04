<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Charts\ChartTemplates;
use Modules\Accounting\Enums\AccountStatus;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Balance;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\PostingAccount;

/**
 * A company's chart of accounts: set up once from a template (rule
 * accounting.chart_template, a sector default), then the company's own to
 * change. Accounts that carry entries keep their type and kind; accounts are
 * archived, never deleted. Posting accounts tell other modules where their
 * entries go (manifest ledger_accounts), so no account is hardcoded.
 */
class ChartOfAccounts
{
    public function __construct(
        private Books $books,
        private ChartTemplates $templates,
        private FiscalCalendar $calendar,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
        private AuditLogger $audit,
        private TaxCodes $taxCodes,
    ) {}

    /** The template the company's rule suggests. */
    public function suggestedTemplate(Organization $company): string
    {
        return (string) $this->rules->get('accounting.chart_template', $this->contexts->forOrganization($company));
    }

    /**
     * Copy a template into the company's accounts, map the posting accounts it
     * names, and add the first fiscal year (if there is none yet).
     *
     * @return array{template: string, accounts: int, fiscal_year_id: string}
     */
    public function setUp(Organization $company, ?string $template, ?string $firstYearStartsOn, User $actor): array
    {
        $template ??= $this->suggestedTemplate($company);
        if (! $this->templates->has($template)) {
            throw ValidationException::withMessages(['template' => __('accounting::accounting.validation.unknown_template')]);
        }

        return $this->books->transaction($company, function () use ($company, $template, $firstYearStartsOn, $actor) {
            if ($this->books->isSetUp($company)) {
                throw AccountingException::alreadySetUp();
            }

            $ids = [];
            foreach ($this->templates->accounts($template) as $row) {
                $account = new Account;
                $account->fill([
                    'organization_id' => $company->getKey(),
                    'parent_id' => $row['parent'] === null ? null : $ids[$row['parent']],
                    'code' => $row['code'],
                    'type' => $row['type'],
                    'is_group' => $row['group'],
                    'status' => AccountStatus::Active,
                    'version' => 1,
                ]);
                $account->putTexts('name', $row['name'])->save();
                $ids[$row['code']] = $account->getKey();
            }

            $known = $this->postingKeys();
            foreach ($this->templates->postings($template) as $key => $code) {
                if (isset($known[$key], $ids[$code])) {
                    $mapping = new PostingAccount;
                    $mapping->fill(['organization_id' => $company->getKey(), 'posting_key' => $key, 'account_id' => $ids[$code], 'version' => 1])->save();
                }
            }

            // The tax codes of the company's country (its own to change afterwards).
            $this->taxCodes->seed($company, $actor);

            $year = $this->books->query(FiscalYear::class, $company)->orderBy('starts_on')->first()
                ?? $this->calendar->addYear($company, $firstYearStartsOn, $actor);

            $this->audit->record('accounting.books_set_up', null, new: ['template' => $template, 'accounts' => count($ids)], actor: $actor, organizationId: $company->getKey());

            return ['template' => $template, 'accounts' => count($ids), 'fiscal_year_id' => $year->getKey()];
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated by AccountRequest.
     */
    public function create(Organization $company, array $data, User $actor): Account
    {
        return $this->books->transaction($company, function () use ($company, $data, $actor) {
            $parent = $this->parent($company, $data['parent_id'] ?? null);
            $type = $this->typeUnder($parent, $data['type'] ?? null);
            $this->assertCodeFree($company, $data['code']);

            $account = new Account;
            $account->fill([
                'organization_id' => $company->getKey(),
                'parent_id' => $parent?->getKey(),
                'code' => $data['code'],
                'type' => $type,
                'is_group' => (bool) ($data['is_group'] ?? false),
                'status' => AccountStatus::Active,
                'version' => 1,
            ]);
            $account->putTexts('name', $data['name'])->save();

            $this->audit->record('accounting.account_created', $account, new: $this->auditValues($account), actor: $actor, organizationId: $company->getKey());

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated by AccountRequest (only the fields to change).
     */
    public function update(Organization $company, Account $account, int $baseVersion, array $data, User $actor): Account
    {
        return $this->books->transaction($company, function () use ($company, $account, $baseVersion, $data, $actor) {
            /** @var Account $account */
            $account = $this->books->query(Account::class, $company)->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if ($account->version !== $baseVersion) {
                throw AccountingException::versionConflict(['version' => $account->version]);
            }
            $old = $this->auditValues($account);

            if (array_key_exists('code', $data) && $data['code'] !== $account->code) {
                $this->assertCodeFree($company, $data['code']);
                $account->code = $data['code'];
            }
            if (isset($data['name'])) {
                $account->putTexts('name', $data['name']);
            }
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $account->parent_id) {
                $this->moveUnder($company, $account, $this->parent($company, $data['parent_id']));
            }
            if (isset($data['type']) && $data['type'] !== $account->type->value) {
                $this->assertUnused($company, $account);
                $account->type = AccountType::from($data['type']);
                $this->typeUnder($this->parent($company, $account->parent_id), $account->type->value);
            }
            if (array_key_exists('is_group', $data) && (bool) $data['is_group'] !== $account->is_group) {
                $this->assertUnused($company, $account);
                $account->is_group = (bool) $data['is_group'];
            }
            if (isset($data['status']) && $data['status'] !== $account->status->value) {
                if ($data['status'] === AccountStatus::Archived->value) {
                    $this->assertArchivable($company, $account);
                }
                $account->status = AccountStatus::from($data['status']);
            }

            if (! $account->isDirty()) {
                return $account;
            }

            $account->version = $account->version + 1;
            $account->save();

            $this->audit->record('accounting.account_updated', $account, old: $old, new: $this->auditValues($account), actor: $actor, organizationId: $company->getKey());

            return $account;
        });
    }

    /**
     * Posting keys modules declare (manifest ledger_accounts), with the kind of account each needs.
     *
     * @return array<string, array{label: string, type: string, module: string}>
     */
    public function postingKeys(): array
    {
        $keys = [];
        foreach ($this->modules->all() as $module) {
            foreach ($module->ledgerAccounts as $key => $definition) {
                $keys[$key] = ['label' => $definition['label'], 'type' => $definition['type'], 'module' => $module->key];
            }
        }
        ksort($keys);

        return $keys;
    }

    /**
     * @return list<array{key: string, label: string, type: string, module: string, module_enabled: bool, account_id: string|null, version: int|null}>
     */
    public function postingAccounts(Organization $company): array
    {
        $mapped = $this->books->query(PostingAccount::class, $company)->get()->keyBy('posting_key');

        return array_values(array_map(fn (string $key, array $definition) => [
            'key' => $key,
            'label' => __($definition['label']),
            'type' => $definition['type'],
            'module' => $definition['module'],
            'module_enabled' => $this->resolver->isEnabled($definition['module'], $company),
            'account_id' => $mapped[$key]->account_id ?? null,
            'version' => $mapped[$key]->version ?? null,
        ], array_keys($this->postingKeys()), $this->postingKeys()));
    }

    /**
     * Posting keys added after a company set up its books (e.g. receivable
     * and payable came with ACC-3) get the account its chart template names,
     * when the company has an active account with that code and the right
     * type. Choices already made are never changed.
     *
     * @return list<string> The keys that were mapped.
     */
    public function mapMissingPostings(Organization $company, ?User $actor = null): array
    {
        $template = $this->suggestedTemplate($company);
        if (! $this->books->isSetUp($company) || ! $this->templates->has($template)) {
            return [];
        }

        return $this->books->transaction($company, function () use ($company, $template, $actor) {
            $known = $this->postingKeys();
            $mapped = $this->books->query(PostingAccount::class, $company)->pluck('posting_key')->all();
            $done = [];
            foreach ($this->templates->postings($template) as $key => $code) {
                if (! isset($known[$key]) || in_array($key, $mapped, true)) {
                    continue;
                }
                $account = $this->books->query(Account::class, $company)->where('code', $code)->first();
                if ($account === null || ! $account->isPostable() || $account->type->value !== $known[$key]['type']) {
                    continue;
                }

                $mapping = new PostingAccount;
                $mapping->fill(['organization_id' => $company->getKey(), 'posting_key' => $key, 'account_id' => $account->getKey(), 'version' => 1])->save();
                $this->audit->record('accounting.posting_account_set', $mapping, new: ['posting_key' => $key, 'account_id' => $account->getKey()], reason: 'Template default for a new posting key', actor: $actor, organizationId: $company->getKey());
                $done[] = $key;
            }

            return $done;
        });
    }

    public function setPostingAccount(Organization $company, string $key, string $accountId, ?int $baseVersion, User $actor): PostingAccount
    {
        $definition = $this->postingKeys()[$key] ?? throw AccountingException::unknownPostingKey();

        return $this->books->transaction($company, function () use ($company, $key, $accountId, $baseVersion, $definition, $actor) {
            $account = $this->books->query(Account::class, $company)->whereKey($accountId)->first();
            if ($account === null || ! $account->isPostable()) {
                throw ValidationException::withMessages(['account_id' => __('accounting::accounting.validation.account_not_postable')]);
            }
            if ($account->type->value !== $definition['type']) {
                throw AccountingException::postingAccountType(__('accounting::accounting.types.'.$definition['type']));
            }

            $mapping = $this->books->query(PostingAccount::class, $company)->where('posting_key', $key)->lockForUpdate()->first();
            if ($mapping !== null && $mapping->version !== $baseVersion) {
                throw AccountingException::versionConflict(['version' => $mapping->version, 'account_id' => $mapping->account_id]);
            }

            $old = $mapping?->account_id;
            $mapping ??= new PostingAccount(['organization_id' => $company->getKey(), 'posting_key' => $key, 'version' => 0]);
            $mapping->account_id = $account->getKey();
            $mapping->version = $mapping->version + 1;
            $mapping->save();

            $this->audit->record('accounting.posting_account_set', $mapping, old: ['account_id' => $old], new: ['posting_key' => $key, 'account_id' => $account->getKey()], actor: $actor, organizationId: $company->getKey());

            return $mapping;
        });
    }

    /**
     * Posted debit and credit totals of an account.
     *
     * @return array{debit: int, credit: int}
     */
    public function totals(Organization $company, Account $account): array
    {
        $rows = $this->books->query(Balance::class, $company)->where('account_id', $account->getKey());

        return ['debit' => (int) (clone $rows)->sum('debit_minor'), 'credit' => (int) (clone $rows)->sum('credit_minor')];
    }

    private function parent(Organization $company, ?string $id): ?Account
    {
        if ($id === null) {
            return null;
        }

        $parent = $this->books->query(Account::class, $company)->whereKey($id)->first();
        if ($parent === null || ! $parent->is_group) {
            throw ValidationException::withMessages(['parent_id' => __('accounting::accounting.validation.parent_not_group')]);
        }

        return $parent;
    }

    /** A sub-account has its group's type; a top-level account names its own. */
    private function typeUnder(?Account $parent, ?string $type): AccountType
    {
        if ($parent === null) {
            return $type === null
                ? throw ValidationException::withMessages(['type' => __('accounting::accounting.validation.type_required')])
                : AccountType::from($type);
        }

        if ($type !== null && $type !== $parent->type->value) {
            throw ValidationException::withMessages(['type' => __('accounting::accounting.validation.type_of_group')]);
        }

        return $parent->type;
    }

    private function moveUnder(Organization $company, Account $account, ?Account $parent): void
    {
        // Never under itself or one of its own sub-accounts.
        for ($step = $parent; $step !== null; $step = $step->parent_id === null ? null : $this->books->query(Account::class, $company)->find($step->parent_id)) {
            if ($step->getKey() === $account->getKey()) {
                throw ValidationException::withMessages(['parent_id' => __('accounting::accounting.validation.parent_loop')]);
            }
        }
        if ($parent !== null && $parent->type !== $account->type) {
            throw ValidationException::withMessages(['parent_id' => __('accounting::accounting.validation.type_of_group')]);
        }

        $account->parent_id = $parent?->getKey();
    }

    private function assertCodeFree(Organization $company, string $code): void
    {
        if ($this->books->query(Account::class, $company)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('accounting::accounting.validation.code_taken')]);
        }
    }

    /** Type and kind stay fixed once an account has entries or sub-accounts. */
    private function assertUnused(Organization $company, Account $account): void
    {
        if ($this->books->query(JournalLine::class, $company)->where('account_id', $account->getKey())->exists()
            || $this->books->query(Account::class, $company)->where('parent_id', $account->getKey())->exists()) {
            throw AccountingException::accountInUse();
        }
    }

    private function assertArchivable(Organization $company, Account $account): void
    {
        $totals = $this->totals($company, $account);
        if ($totals['debit'] !== $totals['credit']) {
            throw AccountingException::accountNotEmpty();
        }
        if ($this->books->query(PostingAccount::class, $company)->where('account_id', $account->getKey())->exists()) {
            throw AccountingException::accountMapped();
        }
        if ($this->books->query(Account::class, $company)->where('parent_id', $account->getKey())->where('status', AccountStatus::Active->value)->exists()) {
            throw AccountingException::accountHasChildren();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Account $account): array
    {
        return [
            'code' => $account->code, 'name' => $account->texts('name'), 'type' => $account->type->value,
            'parent_id' => $account->parent_id, 'is_group' => $account->is_group, 'status' => $account->status->value,
        ];
    }
}
