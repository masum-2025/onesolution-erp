<?php

use App\Platform\DataExport\Services\ExportBuilder;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Export\AccountingExporter;

/*
 * ACC-1: one company's books are invisible to every other company, group
 * and partner; a group sees its own companies; turning Accounting off
 * closes the API without deleting anything.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->posted = journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 0], ['4100', 0, 1000]], ['submit' => true])->assertCreated()->json('data');
});

it('keeps a company\'s journals and accounts away from other companies and partners', function () {
    $journal = "/api/organizations/{$this->w->c1->id}/accounting/journals/{$this->posted['id']}";

    $c2Owner = createMember($this->w->c2);
    $c2Token = orgToken($c2Owner, $this->w->c2);
    $this->asToken($c2Token)->getJson($journal)->assertNotFound();
    $this->asToken($c2Token)->getJson("/api/organizations/{$this->w->c2->id}/accounting/journals/{$this->posted['id']}")->assertNotFound();

    // C2 sets up its own books and cannot use C1's accounts in them.
    setUpBooks($this, $c2Token, $this->w->c2)->assertCreated();
    $c2Writer = orgToken(staffWithRoles($this->w->c2, makeRole($this->w->c2, ['accounting.view', 'accounting.post'], 'Writer')), $this->w->c2);
    $this->asToken($c2Writer)->postJson("/api/organizations/{$this->w->c2->id}/accounting/journals", [
        'entry_date' => '2026-10-15', 'narration' => 'Borrowed accounts', 'lines' => [
            ['account_id' => accountId($this->w->c1, '1110'), 'debit_minor' => 10], ['account_id' => accountId($this->w->c2, '4100'), 'credit_minor' => 10],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
    expect(collect($this->asToken($c2Writer)->getJson("/api/organizations/{$this->w->c2->id}/accounting/journals")->json('data')))->toBeEmpty();

    // C1's people cannot reach C2's books by its address either.
    $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c2->id}/accounting/accounts")->assertNotFound();
    $this->asToken($this->w->approverToken)->postJson("{$journal}/reverse", ['base_version' => 2, 'reason' => 'Not mine'])->assertForbidden();

    // Another partner's client: the same 404.
    $c4Token = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($c4Token)->getJson($journal)->assertNotFound();
    $this->asToken($c4Token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance")->assertNotFound();
});

it('lets a group read the books of its own companies only', function () {
    // Group admins see their companies when their membership reaches below the group.
    $g1Token = orgToken(createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants), $this->w->g1);
    $this->asToken($g1Token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/reports/trial-balance")->assertOk()
        ->assertJsonPath('data.total_debit_minor', 1000);
    $this->asToken($g1Token)->getJson("/api/organizations/{$this->w->g1->id}/accounting/setup")->assertUnprocessable()->assertJsonPath('code', 'not_company');

    $g2Token = orgToken(createMember($this->w->g2, MembershipType::Owner, AccessScope::Descendants), $this->w->g2);
    $this->asToken($g2Token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/journals")->assertNotFound();
});

it('closes the API when Accounting is turned off, keeping the books', function () {
    toggles()->disable($this->w->g1, 'accounting', 'Test setup', confirm: true);

    $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/journals")->assertForbidden();
    journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 0], ['4100', 0, 1000]])->assertForbidden();

    toggles()->enable($this->w->g1, 'accounting', 'Test setup');
    $this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/journals")->assertOk()->assertJsonPath('meta.total', 1);
});

it('hands the books to the client\'s data export, for that client only', function () {
    $c3Token = orgToken(createMember($this->w->c3), $this->w->c3);
    setUpBooks($this, $c3Token, $this->w->c3)->assertCreated();
    app(CurrentContext::class)->clear();

    $ids = Organization::query()->subtreeOf($this->w->c1)->pluck('id')->all();
    $datasets = app(AccountingExporter::class)->export($this->w->c1, $ids);
    $journals = iterator_to_array($datasets['journals'], false);

    expect(array_keys($datasets))->toBe(['accounts', 'fiscal_years', 'periods', 'journals', 'journal_lines', 'posting_accounts', 'parties', 'documents', 'document_lines', 'tax_codes', 'settlements', 'allocations', 'openings', 'opening_lines', 'year_reopen_requests', 'bank_lines', 'bank_matches', 'reconciliations'])
        ->and(array_column($journals, 'id'))->toBe([$this->posted['id']])
        ->and($journals[0])->toMatchArray(['number' => 'JV-2026-00001', 'total_minor' => 1000, 'currency_code' => 'BDT'])
        ->and(iterator_to_array($datasets['journal_lines'], false))->toHaveCount(2)
        ->and(collect(iterator_to_array($datasets['accounts'], false))->pluck('organization_id')->unique()->all())->toBe([$this->w->c1->id]);

    $exporters = (fn () => $this->exporters)->call(app(ExportBuilder::class));
    expect(collect($exporters)->map(fn ($exporter) => $exporter->moduleKey())->all())->toContain('accounting');
});

it('works the same for a client in its own database', function () {
    $dedicated = dedicatedTenantDatabase();
    placeClient($this->w->g2);
    $c3Token = orgToken(createMember($this->w->c3), $this->w->c3);
    setUpBooks($this, $c3Token, $this->w->c3)->assertCreated();

    $writer = orgToken(staffWithRoles($this->w->c3, makeRole($this->w->c3, ['accounting.view', 'accounting.post'], 'Writer')), $this->w->c3);
    journalVia($this, $writer, $this->w->c3, [['1110', 4200, 0], ['4100', 0, 4200]], ['submit' => true])->assertCreated()->assertJsonPath('data.number', 'JV-2026-00001');

    expect(DB::connection($dedicated)->table('acc_journals')->where('organization_id', $this->w->c3->id)->count())->toBe(1)
        ->and(DB::connection($dedicated)->table('acc_balances')->where('organization_id', $this->w->c3->id)->count())->toBe(2)
        ->and(DB::table('acc_journals')->where('organization_id', $this->w->c3->id)->exists())->toBeFalse()
        ->and($this->asToken($writer)->getJson("/api/organizations/{$this->w->c3->id}/accounting/reports/trial-balance")->json('data.total_debit_minor'))->toBe(4200);
});
