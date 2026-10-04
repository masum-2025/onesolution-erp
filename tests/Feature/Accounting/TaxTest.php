<?php

use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Accounting\Models\TaxCode;
use Modules\Accounting\Services\TaxCodes;

/*
 * ACC-4a: tax codes from the country's tax profile, VAT on invoices and
 * bills (on top of prices, or inside them), its journal lines and the VAT
 * report.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.sell', 'accounting.buy'], 'Clerk')), $this->w->c1);
    $this->api = fn (string $path) => "/api/organizations/{$this->w->c1->id}/accounting/{$path}";
    $this->code = fn (string $code) => (string) TaxCode::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->c1->id)->where('code', $code)->value('id');
    $this->party = fn (array $data) => $this->asToken($this->clerk)->postJson(($this->api)('parties'), $data)->json('data.id');
    $this->document = fn (string $type, string $party, array $lines, array $data = []) => $this->asToken($this->clerk)->postJson(($this->api)('documents'), [
        'type' => $type, 'party_id' => $party, 'issue_date' => '2026-10-15', 'submit' => true,
        'lines' => array_map(fn (array $line) => array_filter([
            'description' => 'Item', 'quantity' => '1', 'unit_price_minor' => $line[1], 'account_id' => accountId($this->w->c1, $line[0]),
            'tax_code_id' => isset($line[2]) ? ($this->code)($line[2]) : null,
        ], fn ($value) => $value !== null), $lines),
        ...$data,
    ]);
});

function taxRow(object $test, string $code): ?array
{
    return collect($test->asToken($test->w->viewerToken)->getJson(($test->api)('reports/trial-balance'))->json('data.rows'))->firstWhere('code', $code);
}

it('gives the company the tax codes of its country when it sets up its books', function () {
    $codes = collect($this->asToken($this->w->viewerToken)->getJson(($this->api)('tax-codes'))->assertOk()->json('data'));
    expect($codes->pluck('code')->all())->toBe(['VAT15', 'VAT10', 'VAT7.5', 'VAT5', 'EXEMPT', 'ZERO'])
        ->and($codes->first())->toMatchArray(['rate_bp' => 1500, 'kind' => 'standard', 'applies_to' => 'both', 'name' => 'VAT 15%'])
        ->and($codes->first()['names']['bn'])->toBe('ভ্যাট ১৫%');
});

it('adds VAT on top of an invoice and owes it to the tax office', function () {
    $customer = ($this->party)(['name' => 'Karim Traders', 'is_customer' => true]);
    $invoice = ($this->document)('invoice', $customer, [['4100', 100000, 'VAT15'], ['4200', 33333, 'VAT7.5'], ['4900', 5000]])->assertCreated()->json('data');

    expect($invoice)->toMatchArray(['net_minor' => 138333, 'tax_minor' => 17500, 'total_minor' => 155833, 'prices_include_tax' => false])
        ->and(array_column($invoice['lines'], 'tax_minor'))->toBe([15000, 2500, 0])
        ->and($invoice['lines'][1]['tax_rate_bp'])->toBe(750);

    expect(taxRow($this, '1140')['debit_minor'])->toBe(155833)
        ->and(taxRow($this, '4100')['credit_minor'])->toBe(100000)
        ->and(taxRow($this, '2130')['credit_minor'])->toBe(17500);
});

it('takes VAT out of prices that include it, rounding half up with integers', function () {
    trustedOrgRule($this->w->c1, 'accounting.prices_include_tax', true);
    $customer = ($this->party)(['name' => 'Walk-in customer', 'is_customer' => true]);

    $invoice = ($this->document)('invoice', $customer, [['4100', 115000, 'VAT15'], ['4100', 1000, 'VAT15']])->json('data');
    expect($invoice)->toMatchArray(['prices_include_tax' => true, 'total_minor' => 116000, 'net_minor' => 100870, 'tax_minor' => 15130])
        ->and(TaxCodes::split(1000, 1500, true))->toBe(['net' => 870, 'tax' => 130])
        ->and(TaxCodes::split(333, 1500, false))->toBe(['net' => 333, 'tax' => 50])
        ->and(TaxCodes::split(500, 0, true))->toBe(['net' => 500, 'tax' => 0]);
});

it('claims VAT on purchases back, and credits reduce what is owed', function () {
    $vendor = ($this->party)(['name' => 'Dhaka Paper House', 'is_vendor' => true]);
    $customer = ($this->party)(['name' => 'Karim Traders', 'is_customer' => true]);
    ($this->document)('bill', $vendor, [['5500', 40000, 'VAT15']])->assertCreated();
    ($this->document)('invoice', $customer, [['4100', 200000, 'VAT15']])->assertCreated();
    ($this->document)('credit_note', $customer, [['4100', 20000, 'VAT15']])->assertCreated();

    expect(taxRow($this, '1170')['debit_minor'])->toBe(6000)
        ->and(taxRow($this, '5500')['debit_minor'])->toBe(40000)
        ->and(taxRow($this, '2110')['credit_minor'])->toBe(46000)
        ->and(taxRow($this, '2130')['credit_minor'])->toBe(27000);

    $vat = $this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/vat?from=2026-10-01&to=2026-10-31'))->assertOk()->json('data');
    expect($vat['rows'][0])->toMatchArray(['code' => 'VAT15', 'sales_taxable_minor' => 180000, 'output_tax_minor' => 27000, 'purchases_taxable_minor' => 40000, 'input_tax_minor' => 6000])
        ->and([$vat['output_tax_minor'], $vat['input_tax_minor'], $vat['payable_minor']])->toBe([27000, 6000, 21000]);

    // Another month: nothing.
    expect($this->asToken($this->w->viewerToken)->getJson(($this->api)('reports/vat?from=2026-09-01&to=2026-09-30'))->json('data.payable_minor'))->toBe(0);
});

it('keeps tax codes to the people who look after tax, and to the side they apply to', function () {
    $this->asToken($this->clerk)->postJson(($this->api)('tax-codes'), ['code' => 'SD20', 'name' => ['en' => 'Supplementary duty 20%'], 'rate_bp' => 2000, 'kind' => 'standard', 'applies_to' => 'sales'])->assertForbidden();

    $taxman = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.tax'], 'Tax')), $this->w->c1);
    $sd = $this->asToken($taxman)->postJson(($this->api)('tax-codes'), ['code' => 'SD20', 'name' => ['en' => 'Supplementary duty 20%', 'bn' => 'সম্পূরক শুল্ক ২০%'], 'rate_bp' => 2000, 'kind' => 'standard', 'applies_to' => 'sales'])
        ->assertCreated()->json('data');
    $this->asToken($taxman)->postJson(($this->api)('tax-codes'), ['code' => 'SD20', 'name' => ['en' => 'Again'], 'rate_bp' => 1, 'kind' => 'standard', 'applies_to' => 'both'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->asToken($taxman)->postJson(($this->api)('tax-codes'), ['code' => 'BIG', 'name' => ['en' => 'Too much'], 'rate_bp' => 10001, 'kind' => 'standard', 'applies_to' => 'both'])->assertUnprocessable()->assertJsonValidationErrors('rate_bp');

    $vendor = ($this->party)(['name' => 'Paper', 'is_vendor' => true]);
    ($this->document)('bill', $vendor, [['5500', 1000, 'SD20']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_code_id');

    $this->asToken($taxman)->patchJson(($this->api)("tax-codes/{$sd['id']}"), ['base_version' => 1, 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $customer = ($this->party)(['name' => 'Karim', 'is_customer' => true]);
    ($this->document)('invoice', $customer, [['4100', 1000, 'SD20']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_code_id');
});

it('adds missing country codes to books set up before tax, keeping codes the company changed', function () {
    $codes = fn () => TaxCode::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->c1->id);
    $codes()->whereNot('code', 'VAT15')->delete();
    $codes()->where('code', 'VAT15')->update(['rate_bp' => 1499]);

    $this->artisan('accounting:seed-tax-codes')->expectsOutputToContain('Added 5 tax code(s).')->assertSuccessful();
    expect($codes()->count())->toBe(6)
        ->and($codes()->where('code', 'VAT15')->value('rate_bp'))->toBe(1499);
    $this->artisan('accounting:seed-tax-codes')->expectsOutputToContain('Added 0 tax code(s).');
});
