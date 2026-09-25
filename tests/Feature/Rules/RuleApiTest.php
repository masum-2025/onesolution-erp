<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = tenancyWorld();
    toggles()->enable($this->w->g1, 'payroll', 'Test setup');

    $this->owner = createMember($this->w->c1, MembershipType::Owner);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->rules = "/api/organizations/{$this->w->c1->id}/rules";
});

function findRule(array $groups, string $key): ?array
{
    foreach ($groups as $group) {
        foreach ($group['categories'] as $category) {
            foreach ($category['rules'] as $rule) {
                if ($rule['key'] === $key) {
                    return $rule;
                }
            }
        }
    }

    return null;
}

it('lists rules grouped by module with value, source and editability', function () {
    orgRule($this->w->g1, 'attendance.late_grace_minutes', 20);

    $groups = $this->asToken($this->token)->getJson($this->rules)->assertOk()->json('data');
    $grace = findRule($groups, 'attendance.late_grace_minutes');

    // Modules without rules (e.g. crm) have no group.
    expect(array_column($groups, 'module'))->toContain('core', 'attendance', 'payroll', 'inventory')
        ->and(array_column($groups, 'module'))->not->toContain('crm')
        ->and($grace['value'])->toBe(20)
        ->and($grace['source'])->toBe(['level' => 'group', 'id' => $this->w->g1->id, 'name' => 'G1'])
        ->and($grace['label'])->toBe('Late grace period (minutes)')
        ->and($grace['editable'])->toBeTrue()
        ->and(findRule($groups, 'payroll.tax_slabs')['edit_blocked_by'])->toBe('level_not_allowed')
        ->and(findRule($groups, 'inventory.valuation_method')['edit_blocked_by'])->toBe('module_disabled');
});

it('filters the list by module', function () {
    $groups = $this->asToken($this->token)->getJson("{$this->rules}?module=payroll")->assertOk()->json('data');

    expect(array_column($groups, 'module'))->toBe(['payroll']);
});

it('explains one rule with its trace', function () {
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 15], RuleMode::Constrain);

    $this->asToken($this->token)->getJson("{$this->rules}/attendance.late_grace_minutes")
        ->assertOk()
        ->assertJsonPath('data.constraints', ['max' => 15])
        ->assertJsonPath('data.trace.4.level', 'group')
        ->assertJsonPath('data.trace.4.constrain', ['max' => 15]);
});

it('sets a value and audits it', function () {
    $this->asToken($this->token)->putJson("{$this->rules}/attendance.late_grace_minutes", [
        'mode' => 'set',
        'value' => 12,
        'reason' => 'Traffic in Dhaka',
    ])->assertOk()->assertJsonPath('data.value', 12)->assertJsonPath('message', 'Change saved.');

    expect(ruleFor('attendance.late_grace_minutes', $this->w->b1))->toBe(12)
        ->and(AuditLog::where('action', 'rule.changed')->where('actor_user_id', $this->owner->id)->exists())->toBeTrue();
});

it('rejects a value outside the group limits with the limits in the answer', function () {
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 15], RuleMode::Constrain);

    $this->asToken($this->token)->putJson("{$this->rules}/attendance.late_grace_minutes", [
        'mode' => 'set',
        'value' => 30,
        'reason' => 'Longer grace please',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'violates_constraint')
        ->assertJsonPath('constraints', ['max' => 15]);
});

it('runs sensitive changes through maker-checker', function () {
    $response = $this->asToken($this->token)->putJson("{$this->rules}/payroll.salary_approval_levels", [
        'mode' => 'set',
        'value' => 2,
        'reason' => 'Two approvals for salaries',
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'pending_approval');
    $valueId = $response->json('data.id');

    // The requester cannot approve their own change.
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/{$valueId}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'self_approval');

    // A group owner (ancestor) sees and approves it.
    $groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);
    $groupToken = orgToken($groupOwner, $this->w->g1);

    $this->asToken($groupToken)->getJson("/api/organizations/{$this->w->g1->id}/rule-approvals")
        ->assertOk()
        ->assertJsonPath('data.0.id', $valueId);

    $this->asToken($groupToken)->postJson("/api/organizations/{$this->w->g1->id}/rule-approvals/{$valueId}/approve", ['reason' => 'Agreed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect(ruleFor('payroll.salary_approval_levels', $this->w->c1))->toBe(2);
});

it('rejects a pending change only with a reason', function () {
    $valueId = orgRule($this->w->c1, 'payroll.salary_approval_levels', 3, actor: $this->owner)->id;
    $otherOwner = createMember($this->w->c1, MembershipType::Owner);
    $otherToken = orgToken($otherOwner, $this->w->c1);

    $this->asToken($otherToken)->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/{$valueId}/reject")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->asToken($otherToken)->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/{$valueId}/reject", ['reason' => 'Not agreed with finance'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('does not expose approvals of other companies', function () {
    $valueId = orgRule($this->w->c2, 'attendance.late_grace_minutes', 20)->id;

    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/{$valueId}/approve")
        ->assertNotFound()
        ->assertJsonPath('code', 'value_not_found');
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/rule-approvals/".Str::ulid().'/approve')
        ->assertNotFound();
});

it('resets to the inherited value', function () {
    orgRule($this->w->g1, 'attendance.late_grace_minutes', 20);
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 5);

    $this->asToken($this->token)->deleteJson("{$this->rules}/attendance.late_grace_minutes", ['reason' => 'Follow the group'])
        ->assertOk()
        ->assertJsonPath('message', 'This level now uses the inherited value.');

    expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(20);
});

it('previews which organizations a change would affect', function () {
    orgRule($this->w->b1, 'attendance.late_grace_minutes', 5);

    $changes = $this->asToken($this->token)->postJson("{$this->rules}/attendance.late_grace_minutes/preview", [
        'mode' => 'set',
        'value' => 25,
    ])->assertOk()->json('data');

    // C1 changes; B1 and D1 keep their own branch value.
    expect(array_column($changes, 'organization_id'))->toBe([$this->w->c1->id])
        ->and($changes[0]['from'])->toBe(10)
        ->and($changes[0]['to'])->toBe(25);
});

it('shows history and rolls back to an earlier version', function () {
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 15);
    $this->travel(1)->seconds();
    orgRule($this->w->c1, 'attendance.late_grace_minutes', 25);

    $this->asToken($this->token)->getJson("{$this->rules}/attendance.late_grace_minutes/history")
        ->assertOk()
        ->assertJsonCount(5, 'data');

    $this->asToken($this->token)->postJson("{$this->rules}/attendance.late_grace_minutes/rollback", ['version' => 1, 'reason' => 'Back to 15'])
        ->assertOk()
        ->assertJsonPath('data.version', 3);

    expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(15);
});

it('validates rule writes', function (array $payload, string $field) {
    $this->asToken($this->token)->putJson("{$this->rules}/attendance.late_grace_minutes", [
        'mode' => 'set', 'value' => 12, 'reason' => 'Valid reason', ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing reason' => [['reason' => null], 'reason'],
    'unknown mode' => [['mode' => 'force'], 'mode'],
    'backdated' => [['effective_from' => '2020-01-01'], 'effective_from'],
    'bad country' => [['country_code' => 'bangladesh'], 'country_code'],
    'unknown field' => [['scope_id' => 'x'], 'scope_id'],
]);

it('forbids non-owners from changing rules but lets them read', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);
    $token = orgToken($staff, $this->w->c1);

    $this->asToken($token)->getJson($this->rules)->assertOk();
    $this->asToken($token)->putJson("{$this->rules}/attendance.late_grace_minutes", ['mode' => 'set', 'value' => 12, 'reason' => 'I want it'])
        ->assertForbidden();
});

it('hides rules of organizations outside the context', function (string $key) {
    $id = $this->w->{$key}->id;

    $this->asToken($this->token)->getJson("/api/organizations/{$id}/rules")->assertNotFound();
    $this->asToken($this->token)->putJson("/api/organizations/{$id}/rules/attendance.late_grace_minutes", ['mode' => 'set', 'value' => 1, 'reason' => 'Reaching across'])
        ->assertNotFound();
})->with(['g1', 'c2', 'c4']);

it('answers 404 for an unknown rule', function () {
    $this->asToken($this->token)->getJson("{$this->rules}/hrm.coffee_breaks")
        ->assertNotFound()
        ->assertJsonPath('code', 'unknown_rule');
});

it('explains errors in Bangla for Bangla organizations', function () {
    $this->w->g1->update(['default_locale' => 'bn']);
    orgRule($this->w->g1, 'attendance.late_grace_minutes', ['max' => 15], RuleMode::Constrain);

    $this->asToken(orgToken($this->owner, $this->w->c1))->putJson("{$this->rules}/attendance.late_grace_minutes", [
        'mode' => 'set', 'value' => 30, 'reason' => 'Longer grace please',
    ])->assertUnprocessable()
        ->assertJsonPath('message', '"দেরির ছাড় (মিনিট)"-এর মান G1-এর বেঁধে দেওয়া সীমার বাইরে। সীমার ভেতরের একটি মান বেছে নিন।');
});

describe('partner console', function () {
    beforeEach(function () {
        $this->partnerOwner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
        $this->partnerToken = partnerToken($this->partnerOwner, $this->w->partnerA);
    });

    it('lets a partner owner lock a rule for all its clients', function () {
        $this->asToken($this->partnerToken)->putJson('/api/partner/rules/payroll.pay_cycle', [
            'mode' => 'lock', 'value' => 'monthly', 'reason' => 'Partner-wide monthly payroll',
        ])->assertOk();

        $this->asToken($this->token)->putJson("{$this->rules}/payroll.pay_cycle", [
            'mode' => 'set', 'value' => 'biweekly', 'reason' => 'We pay every two weeks',
        ])->assertUnprocessable()->assertJsonPath('code', 'locked_by_parent');

        expect(ruleFor('payroll.pay_cycle', $this->w->c4))->toBe('monthly'); // other partner: default, unaffected
    });

    it('does not let partner support staff change rules', function () {
        $support = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);

        $this->asToken(partnerToken($support, $this->w->partnerA))->putJson('/api/partner/rules/payroll.pay_cycle', [
            'mode' => 'set', 'value' => 'monthly', 'reason' => 'Support tries',
        ])->assertForbidden();
    });

    it('keeps partner rule values away from other partners', function () {
        $this->asToken($this->partnerToken)->putJson('/api/partner/rules/attendance.late_grace_minutes', [
            'mode' => 'set', 'value' => 30, 'reason' => 'Partner A default',
        ])->assertOk();

        expect(ruleFor('attendance.late_grace_minutes', $this->w->c1))->toBe(30)
            ->and(ruleFor('attendance.late_grace_minutes', $this->w->c4))->toBe(10);
    });
});
