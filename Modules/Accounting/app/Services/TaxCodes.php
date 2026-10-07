<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Countries\CountryCatalog;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\TaxCode;

/**
 * A company's tax codes: copied from its country's tax profile
 * (database/data/tax/{profile}.php) when the books are set up, then the
 * company's own. Works out a line's tax with integers only, half up: on top
 * of the price, or contained in it when prices include tax.
 */
class TaxCodes
{
    /** Basis points in a whole (100%). */
    public const WHOLE = 10000;

    public function __construct(
        private Books $books,
        private OrganizationSettingsResolver $settings,
        private CountryCatalog $countries,
        private AuditLogger $audit,
    ) {}

    /**
     * Add the codes of the company's tax profile it does not have yet (by
     * code). Codes it changed or added itself are left alone.
     *
     * @return int How many were added.
     */
    public function seed(Organization $company, ?User $actor = null): int
    {
        $profile = $this->profile($company);
        if ($profile === null) {
            return 0;
        }

        $existing = $this->books->query(TaxCode::class, $company)->pluck('code')->all();
        $added = 0;
        foreach ($profile['codes'] as $row) {
            if (in_array($row['code'], $existing, true)) {
                continue;
            }
            $code = new TaxCode;
            $code->fill([
                'organization_id' => $company->getKey(), 'code' => $row['code'], 'rate_bp' => $row['rate_bp'],
                'kind' => $row['kind'], 'applies_to' => $row['applies_to'], 'is_active' => true, 'version' => 1,
            ]);
            $code->putTexts('name', $row['name'])->save();
            $added++;
        }
        if ($added > 0) {
            $this->audit->record('accounting.tax_codes_seeded', null, new: ['profile' => $profile['profile'], 'added' => $added], actor: $actor, organizationId: $company->getKey());
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $data  Validated by TaxCodeRequest.
     */
    public function create(Organization $company, array $data, User $actor): TaxCode
    {
        return $this->books->transaction($company, function () use ($company, $data, $actor) {
            $this->assertCodeFree($company, $data['code']);
            $code = new TaxCode;
            $code->fill([
                'organization_id' => $company->getKey(), 'code' => $data['code'], 'rate_bp' => $data['rate_bp'],
                'kind' => $data['kind'], 'applies_to' => $data['applies_to'], 'is_active' => true, 'version' => 1,
            ]);
            $code->putTexts('name', $data['name'])->save();
            $this->audit->record('accounting.tax_code_created', $code, new: $this->auditValues($code), actor: $actor, organizationId: $company->getKey());

            return $code;
        });
    }

    /**
     * A code's name, side and whether it is used may change; its rate and
     * kind too (documents keep the rate they were written with).
     *
     * @param  array<string, mixed>  $data  Only the fields to change.
     */
    public function update(Organization $company, TaxCode $code, int $baseVersion, array $data, User $actor): TaxCode
    {
        return $this->books->transaction($company, function () use ($company, $code, $baseVersion, $data, $actor) {
            /** @var TaxCode $code */
            $code = $this->books->query(TaxCode::class, $company)->whereKey($code->getKey())->lockForUpdate()->firstOrFail();
            if ($code->version !== $baseVersion) {
                throw AccountingException::versionConflict(['version' => $code->version]);
            }
            $old = $this->auditValues($code);
            if (isset($data['code']) && $data['code'] !== $code->code) {
                $this->assertCodeFree($company, $data['code']);
            }
            $code->fill(array_intersect_key($data, array_flip(['code', 'rate_bp', 'kind', 'applies_to', 'is_active'])));
            if (isset($data['name'])) {
                $code->putTexts('name', $data['name']);
            }
            if (! $code->isDirty()) {
                return $code;
            }
            $code->version = $code->version + 1;
            $code->save();
            $this->audit->record('accounting.tax_code_updated', $code, old: $old, new: $this->auditValues($code), actor: $actor, organizationId: $company->getKey());

            return $code;
        });
    }

    /**
     * A line's net amount and tax. With prices excluding tax the typed amount
     * is net and the tax comes on top; with prices including tax the typed
     * amount is gross and the tax is the part of it above net.
     *
     * @return array{net: int, tax: int}
     */
    /**
     * The rate (basis points) of each active sales tax code asked for, for
     * other modules (POS) pricing items by their tax code. Unknown or
     * inactive codes are left out (no tax).
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public function salesRates(Organization $company, array $ids): array
    {
        if ($ids === [] || ! $this->books->isSetUp($company)) {
            return [];
        }

        return $this->books->query(TaxCode::class, $company)->whereIn('id', $ids)->get()
            ->filter(fn (TaxCode $code) => $code->appliesTo('sales'))
            ->mapWithKeys(fn (TaxCode $code) => [$code->getKey() => $code->rate_bp])->all();
    }

    /**
     * The sales tax codes a company uses, for other modules' pickers (CRM
     * quotations): id, code, name in the reader's language, rate.
     *
     * @return list<array{id: string, code: string, name: string, rate_bp: int}>
     */
    public function salesCodes(Organization $company): array
    {
        if (! $this->books->isSetUp($company)) {
            return [];
        }

        return $this->books->query(TaxCode::class, $company)->where('is_active', true)->orderBy('rate_bp')->get()
            ->filter(fn (TaxCode $code) => $code->appliesTo('sales'))
            ->map(fn (TaxCode $code) => ['id' => $code->getKey(), 'code' => $code->code, 'name' => $code->textIn('name'), 'rate_bp' => (int) $code->rate_bp])->values()->all();
    }

    public static function split(int $typedAmountMinor, int $rateBp, bool $pricesIncludeTax): array
    {
        if ($rateBp <= 0) {
            return ['net' => $typedAmountMinor, 'tax' => 0];
        }
        if (! $pricesIncludeTax) {
            return ['net' => $typedAmountMinor, 'tax' => intdiv($typedAmountMinor * $rateBp + intdiv(self::WHOLE, 2), self::WHOLE)];
        }

        $divisor = self::WHOLE + $rateBp;
        $net = intdiv($typedAmountMinor * self::WHOLE + intdiv($divisor, 2), $divisor);

        return ['net' => $net, 'tax' => $typedAmountMinor - $net];
    }

    /**
     * The tax profile of the company's country, when there is one with a file.
     *
     * @return array{profile: string, codes: list<array<string, mixed>>}|null
     */
    private function profile(Organization $company): ?array
    {
        $country = $this->countries->find($this->settings->values($company)['country_code'] ?? null);
        $key = $country?->taxProfile;
        $file = dirname(__DIR__, 2).'/database/data/tax/'.$key.'.php';
        if ($key === null || $key === '' || ! preg_match('/^[a-z0-9_]+$/', $key) || ! is_file($file)) {
            return null;
        }

        return require $file;
    }

    private function assertCodeFree(Organization $company, string $code): void
    {
        if ($this->books->query(TaxCode::class, $company)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('accounting::accounting.validation.code_taken')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(TaxCode $code): array
    {
        return [
            'code' => $code->code, 'name' => $code->texts('name'), 'rate_bp' => $code->rate_bp,
            'kind' => $code->kind, 'applies_to' => $code->applies_to, 'is_active' => $code->is_active,
        ];
    }
}
