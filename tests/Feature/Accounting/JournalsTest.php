<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Events\JournalPosted;
use Modules\Accounting\Models\Balance;
use Modules\Accounting\Models\Journal;

/*
 * ACC-1: journal entries from draft to the books, approval by a second
 * person, reversal, and the company's rules on dates and cost centres.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    setUpBooks($this, $this->w->token, $this->w->c1)->assertCreated();
    $this->journal = fn (string $id, string $path = '') => "/api/organizations/{$this->w->c1->id}/accounting/journals/{$id}{$path}";
});

function stepVia(object $test, string $token, string $url, string $step, array $data): TestResponse
{
    return $test->asToken($token)->postJson("{$url}/{$step}", $data);
}

it('writes a draft, then posts it with the next number and the period totals', function () {
    Event::fake([JournalPosted::class]);

    $draft = journalVia($this, $this->w->token, $this->w->c1, [['1110', 150000, 0], ['4100', 0, 150000]])->assertCreated()->json('data');
    expect($draft)->toMatchArray(['status' => 'draft', 'number' => null, 'currency' => 'BDT', 'total_minor' => 150000])
        ->and($draft['lines'][0])->toMatchArray(['line_no' => 1, 'account_code' => '1110', 'debit_minor' => 150000, 'cost_centre_id' => $this->w->c1->id])
        ->and($draft['can'])->toMatchArray(['edit' => true, 'submit' => true, 'approve' => false]);

    $posted = stepVia($this, $this->w->token, ($this->journal)($draft['id']), 'submit', ['base_version' => 1])->assertOk()->json('data');
    expect($posted)->toMatchArray(['status' => 'posted', 'number' => 'JV-2026-00001', 'version' => 2])
        ->and($posted['can'])->toMatchArray(['edit' => false, 'reverse' => true]);

    $second = journalVia($this, $this->w->token, $this->w->c1, [['5300', 20000, 0], ['1110', 0, 20000]], ['submit' => true])->assertCreated()->json('data');
    expect($second['number'])->toBe('JV-2026-00002');

    $balance = Balance::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('account_id', accountId($this->w->c1, '1110'))->sole();
    expect([$balance->debit_minor, $balance->credit_minor])->toBe([150000, 20000]);

    Event::assertDispatched(JournalPosted::class, fn (JournalPosted $event) => $event->journalId === $draft['id'] && $event->sourceModule === null);
    expect(AuditLog::query()->where('action', 'accounting.journal_posted')->count())->toBe(2);
});

it('refuses unbalanced entries, a single line and amounts on both sides', function () {
    $draft = journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 0], ['4100', 0, 900]])->assertCreated()->json('data');
    stepVia($this, $this->w->token, ($this->journal)($draft['id']), 'submit', ['base_version' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'unbalanced')->assertJsonPath('debit_minor', 1000);

    $single = journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 0]])->assertCreated()->json('data');
    stepVia($this, $this->w->token, ($this->journal)($single['id']), 'submit', ['base_version' => 1])->assertUnprocessable()->assertJsonPath('code', 'too_few_lines');

    journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 1000], ['4100', 0, 0]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.debit_minor', 'lines.1.debit_minor']);
    journalVia($this, $this->w->token, $this->w->c1, [['1000', 1000, 0], ['4100', 0, 1000]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
    $this->asToken($this->w->token)->postJson("/api/organizations/{$this->w->c1->id}/accounting/journals", [
        'entry_date' => '2026-10-15', 'narration' => 'Sneaky', 'lines' => [['account_id' => accountId($this->w->c1, '1110'), 'debit_minor' => 5, 'organization_id' => $this->w->c2->id]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0');

    // Written and sent together: nothing is kept when sending fails.
    journalVia($this, $this->w->token, $this->w->c1, [['1110', 1000, 0], ['4100', 0, 999]], ['submit' => true])->assertUnprocessable();
    expect(Journal::query()->count())->toBe(2);
});

it('lets a second person approve entries above the approval amount, never the writer', function () {
    // Set at the group: every company below follows it.
    trustedOrgRule($this->w->g1, 'accounting.journal_approval_above', ['amount' => 100000, 'currency' => 'BDT']);

    $small = journalVia($this, $this->w->token, $this->w->c1, [['5500', 100000, 0], ['1110', 0, 100000]], ['submit' => true])->json('data');
    expect($small['status'])->toBe('posted');

    $big = journalVia($this, $this->w->token, $this->w->c1, [['5500', 100001, 0], ['1110', 0, 100001]], ['submit' => true])->json('data');
    expect($big)->toMatchArray(['status' => 'pending_approval', 'number' => null]);
    $url = ($this->journal)($big['id']);

    // The writer cannot approve (no permission); an approver who wrote it could not either.
    stepVia($this, $this->w->token, $url, 'approve', ['base_version' => $big['version']])->assertForbidden();
    $both = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['accounting.view', 'accounting.approve'], 'Approver 2'));
    expect($this->asToken(orgToken($both, $this->w->c1))->getJson($url)->json('data.can.approve'))->toBeTrue();
    Journal::query()->whereKey($big['id'])->update(['submitted_by' => $both->id]);
    stepVia($this, orgToken($both, $this->w->c1), $url, 'approve', ['base_version' => $big['version']])->assertForbidden()->assertJsonPath('code', 'own_journal');

    $approved = stepVia($this, $this->w->approverToken, $url, 'approve', ['base_version' => $big['version']])->assertOk()->json('data');
    expect($approved)->toMatchArray(['status' => 'posted', 'number' => 'JV-2026-00002', 'approved_by' => $this->w->approver->id]);
    stepVia($this, $this->w->approverToken, $url, 'approve', ['base_version' => $approved['version']])->assertStatus(409)->assertJsonPath('code', 'not_pending');
});

it('sends rejected entries back to be changed, and lets the sender take one back', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 0, 'currency' => 'BDT']);
    $sent = journalVia($this, $this->w->token, $this->w->c1, [['5500', 5000, 0], ['1110', 0, 5000]], ['submit' => true])->json('data');
    $url = ($this->journal)($sent['id']);

    $this->asToken($this->w->token)->patchJson($url, ['base_version' => $sent['version'], 'narration' => 'Too late'])->assertStatus(409)->assertJsonPath('code', 'not_editable');
    stepVia($this, $this->w->approverToken, $url, 'reject', ['base_version' => $sent['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $rejected = stepVia($this, $this->w->approverToken, $url, 'reject', ['base_version' => $sent['version'], 'reason' => 'Wrong account'])->assertOk()->json('data');
    expect($rejected)->toMatchArray(['status' => 'rejected', 'reject_reason' => 'Wrong account']);

    $fixed = $this->asToken($this->w->token)->patchJson($url, ['base_version' => $rejected['version'], 'lines' => [
        ['account_id' => accountId($this->w->c1, '5600'), 'debit_minor' => 5000], ['account_id' => accountId($this->w->c1, '1110'), 'credit_minor' => 5000],
    ]])->assertOk()->json('data');
    expect($fixed['status'])->toBe('draft')->and($fixed['lines'][0]['account_code'])->toBe('5600');

    $again = stepVia($this, $this->w->token, $url, 'submit', ['base_version' => $fixed['version']])->assertOk()->json('data');
    expect($again['status'])->toBe('pending_approval');
    stepVia($this, $this->w->approverToken, $url, 'withdraw', ['base_version' => $again['version']])->assertForbidden();
    stepVia($this, $this->w->token, $url, 'withdraw', ['base_version' => $again['version']])->assertOk()->assertJsonPath('data.status', 'draft');

    $this->asToken($this->w->token)->deleteJson($url, ['base_version' => $again['version'] + 1])->assertNoContent();
    expect(Journal::query()->whereKey($sent['id'])->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'accounting.journal_deleted')->count())->toBe(1);
});

it('keeps people inside the dates the company allows, and out of closed periods', function () {
    $entry = fn (string $date) => journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0], ['1110', 0, 100]], ['submit' => true, 'entry_date' => $date]);

    // Default: 7 days back, nothing ahead (today is 15 October in Dhaka).
    $entry('2026-10-08')->assertCreated();
    $entry('2026-10-07')->assertUnprocessable()->assertJsonPath('code', 'too_old');
    $entry('2026-10-16')->assertUnprocessable()->assertJsonPath('code', 'too_far_ahead');

    trustedOrgRule($this->w->c1, 'accounting.allow_backdated_entries_days', 366);
    $entry('2026-06-30')->assertUnprocessable()->assertJsonPath('code', 'no_period');

    $september = collect($this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/fiscal-years")->json('data.0.periods'))->firstWhere('number', 3);
    $periodUrl = "/api/organizations/{$this->w->c1->id}/accounting/periods/{$september['id']}";
    $this->asToken($this->w->token)->postJson("{$periodUrl}/close")->assertForbidden();
    $this->asToken($this->w->approverToken)->postJson("{$periodUrl}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    $entry('2026-09-20')->assertUnprocessable()->assertJsonPath('code', 'period_closed');
    $this->asToken($this->w->approverToken)->postJson("{$periodUrl}/reopen")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->asToken($this->w->approverToken)->postJson("{$periodUrl}/reopen", ['reason' => 'Late supplier bill'])->assertOk();
    $entry('2026-09-20')->assertCreated();

    expect(AuditLog::query()->where('action', 'accounting.period_reopened')->sole()->reason)->toBe('Late supplier bill');
});

it('does not close a period while entries in it wait for approval', function () {
    trustedOrgRule($this->w->c1, 'accounting.journal_approval_above', ['amount' => 0, 'currency' => 'BDT']);
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0], ['1110', 0, 100]], ['submit' => true])->assertCreated();

    $october = collect($this->asToken($this->w->token)->getJson("/api/organizations/{$this->w->c1->id}/accounting/fiscal-years")->json('data.0.periods'))->firstWhere('number', 4);
    $this->asToken($this->w->approverToken)->postJson("/api/organizations/{$this->w->c1->id}/accounting/periods/{$october['id']}/close")
        ->assertStatus(409)->assertJsonPath('code', 'pending_in_period');
});

it('undoes a posted entry only by reversing it, once', function () {
    $posted = journalVia($this, $this->w->token, $this->w->c1, [['1110', 70000, 0], ['4100', 0, 70000]], ['submit' => true])->json('data');
    $url = ($this->journal)($posted['id']);

    $this->asToken($this->w->token)->patchJson($url, ['base_version' => $posted['version'], 'narration' => 'Changed'])->assertStatus(409)->assertJsonPath('code', 'not_editable');
    $this->asToken($this->w->token)->deleteJson($url, ['base_version' => $posted['version']])->assertStatus(409);

    $reversal = stepVia($this, $this->w->token, $url, 'reverse', ['base_version' => $posted['version'], 'reason' => 'Entered twice'])->assertCreated()->json('data');
    expect($reversal)->toMatchArray(['status' => 'posted', 'number' => 'JV-2026-00002', 'reverses_id' => $posted['id'], 'narration' => 'Reversal of JV-2026-00001: Entered twice'])
        ->and($reversal['lines'][0])->toMatchArray(['account_code' => '1110', 'debit_minor' => 0, 'credit_minor' => 70000]);

    $original = $this->asToken($this->w->token)->getJson($url)->json('data');
    expect($original['reversed_by_id'])->toBe($reversal['id'])->and($original['can']['reverse'])->toBeFalse();
    stepVia($this, $this->w->token, $url, 'reverse', ['base_version' => $original['version'], 'reason' => 'Again please'])->assertStatus(409)->assertJsonPath('code', 'already_reversed');
    stepVia($this, $this->w->token, ($this->journal)($reversal['id']), 'reverse', ['base_version' => $reversal['version'], 'reason' => 'Undo undo'])
        ->assertUnprocessable()->assertJsonPath('code', 'reversal_of_reversal');

    expect(fn () => Journal::query()->whereKey($posted['id'])->first()->update(['narration' => 'x']))->toThrow(LogicException::class);
});

it('takes cost centres of the company only, and requires one when the rule says so', function () {
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0, $this->w->b2->id], ['1110', 0, 100]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.cost_centre_id');
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0, $this->w->d1->id], ['1110', 0, 100, $this->w->b1->id]], ['submit' => true])->assertCreated();

    orgRule($this->w->c1, 'accounting.require_cost_centre', true);
    journalVia($this, $this->w->token, $this->w->c1, [['5500', 100, 0, $this->w->d1->id], ['1110', 0, 100]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.1.cost_centre_id');
});

it('numbers journals by the company format, and follows a lock from the partner', function () {
    orgRule($this->w->c1, 'accounting.journal_number_format', 'GL/{FY}/{SEQ:3}');
    expect(journalVia($this, $this->w->token, $this->w->c1, [['1110', 100, 0], ['4100', 0, 100]], ['submit' => true])->json('data.number'))->toBe('GL/2026-27/001');

    // The partner locks the approval amount for all its clients; the company cannot loosen it.
    ruleService()->set(app(RuleTargets::class)->partner($this->w->partnerA), 'accounting.journal_approval_above', RuleMode::Lock, ['amount' => 0, 'currency' => 'BDT'], 'Partner policy', trusted: true);
    expect(fn () => orgRule($this->w->c1, 'accounting.journal_approval_above', null))->toThrow(Exception::class);
    expect(journalVia($this, $this->w->token, $this->w->c1, [['1110', 100, 0], ['4100', 0, 100]], ['submit' => true])->json('data.status'))->toBe('pending_approval');
});

it('lists journals newest first with filters', function () {
    journalVia($this, $this->w->token, $this->w->c1, [['1110', 100, 0], ['4100', 0, 100]], ['submit' => true, 'entry_date' => '2026-10-10', 'narration' => 'Shop sale']);
    journalVia($this, $this->w->token, $this->w->c1, [['5300', 300, 0], ['1120', 0, 300]], ['entry_date' => '2026-10-14', 'narration' => 'Office rent']);

    $list = fn (string $query = '') => $this->asToken($this->w->viewerToken)->getJson("/api/organizations/{$this->w->c1->id}/accounting/journals{$query}")->assertOk()->json();
    expect(array_column($list()['data'], 'narration'))->toBe(['Office rent', 'Shop sale'])
        ->and(array_column($list('?status=posted')['data'], 'narration'))->toBe(['Shop sale'])
        ->and(array_column($list('?account_id='.accountId($this->w->c1, '1120'))['data'], 'narration'))->toBe(['Office rent'])
        ->and(array_column($list('?q=JV-2026')['data'], 'narration'))->toBe(['Shop sale'])
        ->and($list('?per_page=1')['meta'])->toMatchArray(['total' => 2, 'last_page' => 2]);

    // Viewers read but do not write.
    journalVia($this, $this->w->viewerToken, $this->w->c1, [['1110', 100, 0], ['4100', 0, 100]])->assertForbidden();
});
