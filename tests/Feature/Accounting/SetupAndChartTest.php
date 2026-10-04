<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditQuery;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Accounting\Models\PostingAccount;

/*
 * ACC-1: setting up a company's books from a chart template, fiscal years,
 * and changing the chart of accounts.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    $this->url = fn (string $path = '') => "/api/organizations/{$this->w->c1->id}/accounting{$path}";
});

it('sets up the books once: the chart, posting accounts and the first fiscal year in twelve periods', function () {
    $status = $this->asToken($this->w->token)->getJson(($this->url)('/setup'))->assertOk()->json('data');
    expect($status)->toMatchArray(['set_up' => false, 'currency' => 'BDT', 'suggested_template' => 'general', 'can_set_up' => true])
        ->and(array_column($status['templates'], 'key'))->toBe(['factory', 'general', 'retail', 'school']);

    $result = setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated()->json('data');
    expect($result['template'])->toBe('general')->and($result['accounts'])->toBeGreaterThan(30);

    $accounts = collect($this->asToken($this->w->token)->getJson(($this->url)('/accounts'))->json('data'))->keyBy('code');
    expect($accounts['1110'])->toMatchArray(['name' => 'Cash in hand', 'type' => 'asset', 'is_group' => false, 'parent_id' => $accounts['1100']['id']])
        ->and($accounts['1110']['names']['bn'])->toBe('হাতে নগদ')
        ->and($accounts['2000']['is_group'])->toBeTrue();

    $years = $this->asToken($this->w->token)->getJson(($this->url)('/fiscal-years'))->json('data');
    expect($years)->toHaveCount(1)
        ->and($years[0])->toMatchArray(['name' => '2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])
        ->and($years[0]['periods'])->toHaveCount(12)
        ->and($years[0]['periods'][11])->toMatchArray(['starts_on' => '2027-06-01', 'ends_on' => '2027-06-30', 'status' => 'open']);

    $postings = collect($this->asToken($this->w->token)->getJson(($this->url)('/posting-accounts'))->json('data'))->keyBy('key');
    expect($postings['accounting.retained_earnings']['account_id'])->toBe($accounts['3200']['id'])
        ->and($postings['accounting.retained_earnings']['label'])->toBe('Retained earnings (year-end closing)');

    setUpBooks($this, $this->w->token, $this->w->c1)->assertStatus(409)->assertJsonPath('code', 'already_set_up');
    expect(AuditLog::query()->where('action', 'accounting.books_set_up')->count())->toBe(1)
        ->and(app(AuditQuery::class)->label('accounting.books_set_up'))->toBe('Books set up');
});

it('starts from the sector template and the fiscal year start of the company rules', function () {
    orgRule($this->w->c1, 'accounting.chart_template', 'school');
    trustedOrgRule($this->w->c1, 'accounting.fiscal_year_start', '04-01');

    $this->asToken($this->w->token)->postJson(($this->url)('/setup'), [])->assertCreated()->assertJsonPath('data.template', 'school');

    $codes = collect($this->asToken($this->w->token)->getJson(($this->url)('/accounts'))->json('data'))->pluck('name', 'code');
    expect($codes['4110'])->toBe('Tuition fees')
        ->and($codes->has('4100'))->toBeFalse()
        ->and($this->asToken($this->w->token)->getJson(($this->url)('/fiscal-years'))->json('data.0'))->toMatchArray(['name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
});

it('adds fiscal years one after another, without gaps', function () {
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();

    $this->asToken($this->w->token)->postJson(($this->url)('/fiscal-years'), ['starts_on' => '2027-08-01'])
        ->assertUnprocessable()->assertJsonPath('code', 'year_not_next');
    $this->asToken($this->w->token)->postJson(($this->url)('/fiscal-years'), [])
        ->assertCreated()->assertJsonPath('data.starts_on', '2027-07-01')->assertJsonPath('data.name', '2027-28');
});

it('keeps books only at a company, and only people who may set them up do', function () {
    $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->b1->id}/accounting/setup")
        ->assertUnprocessable()->assertJsonPath('code', 'not_company');

    setUpBooks($this, $this->w->viewerToken, $this->w->c1)->assertForbidden();
    $this->asToken($this->w->viewerToken)->getJson(($this->url)('/setup'))->assertOk()->assertJsonPath('data.can_set_up', false);

    $this->asToken($this->w->token)->getJson(($this->url)('/reports/trial-balance'))->assertStatus(409)->assertJsonPath('code', 'not_set_up');
    setUpBooks($this, $this->w->token, $this->w->c1, ['template' => 'bakery'])->assertUnprocessable()->assertJsonValidationErrors('template');
});

it('adds and changes accounts under groups of the same type, with unique codes', function () {
    setUpBooks($this, $this->w->token, $this->w->c1);
    $current = accountId($this->w->c1, '1100');

    $created = $this->asToken($this->w->token)->postJson(($this->url)('/accounts'), [
        'code' => '1125', 'name' => ['en' => 'Petty cash', 'bn' => 'খুচরা নগদ'], 'parent_id' => $current,
    ])->assertCreated()->json('data');
    expect($created)->toMatchArray(['type' => 'asset', 'parent_id' => $current, 'version' => 1]);

    $this->asToken($this->w->token)->postJson(($this->url)('/accounts'), ['code' => '1125', 'name' => ['en' => 'Again'], 'parent_id' => $current])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->asToken($this->w->token)->postJson(($this->url)('/accounts'), ['code' => '1126', 'name' => ['en' => 'Wrong'], 'parent_id' => $current, 'type' => 'expense'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->asToken($this->w->token)->postJson(($this->url)('/accounts'), ['code' => '1127', 'name' => ['en' => 'Under cash'], 'parent_id' => accountId($this->w->c1, '1110')])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->asToken($this->w->token)->postJson(($this->url)('/accounts'), ['code' => '6000', 'name' => ['en' => 'Top'], 'organization_id' => $this->w->c2->id, 'type' => 'expense'])
        ->assertUnprocessable()->assertJsonValidationErrors('organization_id');

    $url = ($this->url)('/accounts/'.$created['id']);
    $renamed = $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'name' => ['en' => 'Petty cash box']])->assertOk()->json('data');
    expect($renamed)->toMatchArray(['name' => 'Petty cash box', 'version' => 2]);
    $this->asToken($this->w->token)->patchJson($url, ['base_version' => 1, 'code' => '1128'])->assertStatus(409)->assertJsonPath('code', 'version_conflict');

    // A group cannot move under its own sub-account.
    $this->asToken($this->w->token)->patchJson(($this->url)('/accounts/'.$current), ['base_version' => 1, 'parent_id' => accountId($this->w->c1, '1200')])->assertOk();
    $this->asToken($this->w->token)->patchJson(($this->url)('/accounts/'.accountId($this->w->c1, '1000')), ['base_version' => 1, 'parent_id' => $current])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

    expect(AuditLog::query()->where('action', 'accounting.account_updated')->count())->toBe(2);
});

it('archives an account only when it is empty, unmapped and has no active sub-accounts', function () {
    setUpBooks($this, $this->w->token, $this->w->c1);
    journalVia($this, $this->w->token, $this->w->c1, [['1110', 50000, 0], ['3100', 0, 50000]], ['submit' => true])->assertCreated();

    $archive = fn (string $code) => $this->asToken($this->w->token)->patchJson(($this->url)('/accounts/'.accountId($this->w->c1, $code)), ['base_version' => 1, 'status' => 'archived']);
    $archive('1110')->assertStatus(409)->assertJsonPath('code', 'account_not_empty');
    $archive('3200')->assertStatus(409)->assertJsonPath('code', 'account_mapped');
    $archive('1200')->assertStatus(409)->assertJsonPath('code', 'account_has_children');
    $archive('5300')->assertOk()->assertJsonPath('data.status', 'archived');

    // Kind and type are fixed once an account carries entries.
    $this->asToken($this->w->token)->patchJson(($this->url)('/accounts/'.accountId($this->w->c1, '1110')), ['base_version' => 1, 'is_group' => true])
        ->assertStatus(409)->assertJsonPath('code', 'account_in_use');

    // Archived accounts are left out unless asked for, and take no new entries.
    expect(collect($this->asToken($this->w->token)->getJson(($this->url)('/accounts'))->json('data'))->pluck('code'))->not->toContain('5300')
        ->and(collect($this->asToken($this->w->token)->getJson(($this->url)('/accounts?archived=1'))->json('data'))->pluck('code'))->toContain('5300');
    journalVia($this, $this->w->token, $this->w->c1, [['5300', 1000, 0], ['1110', 0, 1000]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
});

it('maps posting keys to accounts of the type they need', function () {
    setUpBooks($this, $this->w->token, $this->w->c1);
    $url = ($this->url)('/posting-accounts/accounting.opening_balance');

    $this->asToken($this->w->token)->putJson($url, ['account_id' => accountId($this->w->c1, '1110'), 'base_version' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'posting_account_type');
    $this->asToken($this->w->token)->putJson($url, ['account_id' => accountId($this->w->c1, '3100'), 'base_version' => 2])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->w->token)->putJson($url, ['account_id' => accountId($this->w->c1, '3100'), 'base_version' => 1])
        ->assertOk()->assertJsonPath('data.version', 2);
    $this->asToken($this->w->token)->putJson(($this->url)('/posting-accounts/payroll.nothing'), ['account_id' => accountId($this->w->c1, '3100')])
        ->assertNotFound()->assertJsonPath('code', 'unknown_posting_key');
    $this->asToken($this->w->viewerToken)->putJson($url, ['account_id' => accountId($this->w->c1, '3100'), 'base_version' => 2])->assertForbidden();
});

it('maps posting keys added after the books were set up, never changing a choice', function () {
    setUpBooks($this, $this->w->token, $this->w->c1);
    $mappings = fn () => PostingAccount::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $this->w->c1->id);
    // As if receivable and payable came in a later release, and someone chose their own payable account.
    $mappings()->whereIn('posting_key', ['accounting.receivable', 'accounting.payable'])->delete();
    $this->asToken($this->w->token)->putJson(($this->url)('/posting-accounts/accounting.payable'), ['account_id' => accountId($this->w->c1, '2140')])->assertOk();

    $this->artisan('accounting:map-postings')->expectsOutputToContain('Mapped 1 posting account(s).')->assertSuccessful();
    expect($mappings()->pluck('account_id', 'posting_key')->all())->toMatchArray([
        'accounting.receivable' => accountId($this->w->c1, '1140'),
        'accounting.payable' => accountId($this->w->c1, '2140'),
    ]);
    $this->artisan('accounting:map-postings')->expectsOutputToContain('Mapped 0 posting account(s).');
});
