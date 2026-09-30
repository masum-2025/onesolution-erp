<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Audit\AuditRetention;
use App\Platform\Audit\Exports\AuditExport;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Exceptions\RuleException;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 9-1, advanced_audit (business and enterprise plans): reports, CSV
 * exports and retention, only while the module is on, only with its
 * permissions, only over the organization's own entries.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    toggles()->enable($this->w->c1, 'advanced_audit', 'Test setup');

    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->base = "/api/organizations/{$this->w->c1->id}/audit-log";
    AuditLog::query()->toBase()->delete();
});

function oldEntry(?string $organizationId, string $action, int $daysAgo, array $attributes = []): AuditLog
{
    $entry = new AuditLog;
    $entry->forceFill(['organization_id' => $organizationId, 'action' => $action, 'created_at' => now()->subDays($daysAgo), ...$attributes])->save();

    return $entry;
}

/**
 * A retention value at an organization, as an approved change (setup only).
 */
function retention(Organization $organization, string $key, ?int $days): void
{
    ruleService()->set(app(RuleTargets::class)->organization($organization->fresh()), $key, RuleMode::Set, $days, 'Test setup', trusted: true);
}

it('is refused while the module is off, and without its permission', function () {
    $this->asToken($this->token)->getJson("{$this->base}/report")->assertOk();

    // Another company of the same group, where the module is off.
    $c2Owner = createMember($this->w->c2);
    $this->asToken(orgToken($c2Owner, $this->w->c2))->getJson("/api/organizations/{$this->w->c2->id}/audit-log/report")->assertForbidden();
    $this->asToken(orgToken($c2Owner, $this->w->c2))->postJson("/api/organizations/{$this->w->c2->id}/audit-log/exports", ['from' => '2026-09-01', 'to' => '2026-09-30'])->assertForbidden();

    // Staff without a role holding advanced_audit.view / .export.
    $staff = orgToken(createMember($this->w->c1, MembershipType::Staff), $this->w->c1);
    $this->asToken($staff)->getJson("{$this->base}/report")->assertForbidden();
    $this->asToken($staff)->getJson("{$this->base}/exports")->assertForbidden();

    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['advanced_audit.view']));
    $this->asToken(orgToken($viewer, $this->w->c1))->getJson("{$this->base}/report")->assertOk();
    $this->asToken(orgToken($viewer, $this->w->c1))->getJson("{$this->base}/exports")->assertForbidden();
});

it('reports per day, action and person over the organization and its units only', function () {
    oldEntry($this->w->c1->id, 'rule.changed', 1, ['actor_user_id' => $this->owner->id]);
    oldEntry($this->w->b1->id, 'rule.changed', 1, ['actor_user_id' => $this->owner->id]);
    oldEntry($this->w->b1->id, 'support.accessed', 3);
    oldEntry($this->w->c1->id, 'billing.invoice_paid', 3);
    oldEntry($this->w->c2->id, 'rule.changed', 1);
    oldEntry($this->w->c1->id, 'rule.changed', 40);

    $report = $this->asToken($this->token)->getJson("{$this->base}/report?".http_build_query([
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ]))->assertOk()->json('data');

    expect($report['total'])->toBe(4)
        ->and($report['support'])->toBe(1)
        ->and($report['money'])->toBe(1)
        ->and($report['people'])->toBe(1)
        ->and($report['days'])->toHaveCount(8)
        ->and(array_sum(array_column($report['days'], 'count')))->toBe(4)
        ->and($report['actions'][0])->toMatchArray(['action' => 'rule.changed', 'count' => 2, 'label' => __('audit.actions.rule_changed')])
        ->and($report['actors'][0])->toBe(['id' => $this->owner->id, 'name' => $this->owner->name, 'count' => 2]);
});

it('refuses a report period longer than allowed, in the reader\'s language', function () {
    $this->asToken($this->token)->getJson("{$this->base}/report?from=2026-01-01&to=2026-09-30")
        ->assertUnprocessable()
        ->assertJsonPath('errors.to.0', __('audit.errors.period_too_long', ['days' => 92]));
});

it('exports the log as CSV: own entries only, safe for spreadsheets, downloadable for a while', function () {
    // Midday in Dhaka: "today" is the same date in UTC and in the organization's time zone.
    $this->travelTo(now('UTC')->setTime(6, 0));
    oldEntry($this->w->c1->id, 'rule.changed', 2, ['actor_user_id' => $this->owner->id, 'reason' => '=HYPERLINK("http://evil.test")']);
    oldEntry($this->w->b1->id, 'module.enabled', 1);
    oldEntry($this->w->c2->id, 'rule.changed', 1, ['reason' => 'sister company']);

    $export = $this->asToken($this->token)->postJson("{$this->base}/exports", [
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ])->assertStatus(202)->json('data');

    // The queue runs at once in tests.
    $ready = AuditExport::query()->findOrFail($export['id']);
    // Two entries of C1 and its branch, and the export request itself.
    expect($ready->status)->toBe(AuditExport::READY)->and($ready->rows)->toBe(3);

    $csv = Storage::disk('local')->get($ready->file_path);
    expect($csv)->toStartWith("\u{FEFF}created_at_utc,organization,actor")
        ->toContain('\'=HYPERLINK')
        ->not->toContain('sister company')
        ->and(AuditLog::query()->where('action', 'audit.export_requested')->exists())->toBeTrue();

    $url = $this->asToken($this->token)->getJson("{$this->base}/exports/{$export['id']}/link")->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect(AuditLog::query()->where('action', 'audit.export_downloaded')->exists())->toBeTrue();

    // A tampered link is refused.
    $this->get($url.'x')->assertForbidden();

    // After the retention period the file is gone.
    $this->travel(8)->days();
    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditExport::query()->find($export['id'])->status)->toBe(AuditExport::EXPIRED)
        ->and(Storage::disk('local')->exists($ready->file_path))->toBeFalse();
});

it('builds one export at a time and hides other organizations\' exports', function () {
    $running = AuditExport::create(['organization_id' => $this->w->c1->id, 'requested_by' => $this->owner->id, 'status' => AuditExport::QUEUED]);

    $this->asToken($this->token)->postJson("{$this->base}/exports", ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->assertUnprocessable()->assertJsonPath('code', 'already_running');

    $other = AuditExport::create(['organization_id' => $this->w->c2->id, 'requested_by' => $this->owner->id, 'status' => AuditExport::READY]);
    $this->asToken($this->token)->getJson("{$this->base}/exports/{$other->id}/link")->assertNotFound();
    expect(collect($this->asToken($this->token)->getJson("{$this->base}/exports")->json('data'))->pluck('id')->all())->toBe([$running->id]);

    $this->asToken($this->token)->postJson("{$this->base}/exports", ['from' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('keeps audit retention at a year or more, set with a second person\'s approval', function () {
    expect(fn () => retention($this->w->c1, 'advanced_audit.retention_days', 100))->toThrow(RuleException::class);
    expect(fn () => retention($this->w->c1, 'advanced_audit.money_retention_days', 400))->toThrow(RuleException::class);

    $value = ruleService()->set(app(RuleTargets::class)->organization($this->w->c1->fresh()), 'advanced_audit.retention_days', RuleMode::Set, 400, 'Shorter history', $this->owner);
    expect($value->status->value)->toBe('pending_approval');
});

it('removes entries past the retention: general and money apart, only where the module is on', function () {
    retention($this->w->c1, 'advanced_audit.retention_days', 400);
    retention($this->w->c1, 'advanced_audit.money_retention_days', 3000);
    // C2 had a retention while it used the module, then turned the module off.
    toggles()->enable($this->w->c2, 'advanced_audit', 'Test setup');
    retention($this->w->c2, 'advanced_audit.retention_days', 400);
    toggles()->disable($this->w->c2, 'advanced_audit', 'No longer needed');

    $oldGeneral = oldEntry($this->w->c1->id, 'rule.changed', 500);
    $oldInBranch = oldEntry($this->w->b1->id, 'module.enabled', 500);
    $recent = oldEntry($this->w->c1->id, 'rule.changed', 100);
    $oldMoney = oldEntry($this->w->c1->id, 'billing.invoice_paid', 500);
    $ancientMoney = oldEntry($this->w->c1->id, 'payments.refund_due', 3100);
    $moduleOff = oldEntry($this->w->c2->id, 'rule.changed', 500);
    $platform = oldEntry(null, 'backup.created', 5000);

    $removed = app(AuditRetention::class)->prune();

    expect($removed[$this->w->c1->id])->toBe(['general' => 2, 'money' => 1])
        ->and(AuditLog::query()->whereKey([$oldGeneral->id, $oldInBranch->id, $ancientMoney->id])->count())->toBe(0)
        ->and(AuditLog::query()->whereKey([$recent->id, $oldMoney->id, $moduleOff->id, $platform->id])->count())->toBe(4);

    $record = AuditLog::query()->where('action', 'audit.pruned')->sole();
    expect($record->organization_id)->toBe($this->w->c1->id)
        ->and($record->new_values)->toMatchArray(['general' => 2, 'money' => 1, 'retention_days' => 400]);
});

it('keeps everything when no retention is set', function () {
    $old = oldEntry($this->w->c1->id, 'rule.changed', 5000);

    expect(app(AuditRetention::class)->prune())->toBe([])
        ->and(AuditLog::query()->whereKey($old->id)->exists())->toBeTrue();
});
