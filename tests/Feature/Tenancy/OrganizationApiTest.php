<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->c1Owner = createMember($this->w->c1, MembershipType::Owner);
    $this->c1Token = orgToken($this->c1Owner, $this->w->c1);
});

it('requires authentication', function () {
    $this->getJson('/api/organizations')->assertUnauthorized();
});

it('lists only the current company subtree', function () {
    $ids = $this->asToken($this->c1Token)->getJson('/api/organizations')
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toEqualCanonicalizing([$this->w->c1->id, $this->w->b1->id, $this->w->d1->id]);
});

it('returns 404 for organizations outside the context (IDOR)', function (string $key) {
    $id = $this->w->{$key}->id;

    $this->asToken($this->c1Token)->getJson("/api/organizations/{$id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'organization_not_found');
    $this->asToken($this->c1Token)->patchJson("/api/organizations/{$id}", ['name' => ['en' => 'Hacked']])
        ->assertNotFound();
    $this->asToken($this->c1Token)->getJson("/api/organizations/{$id}/settings")->assertNotFound();

    expect(Organization::find($id)->texts('name')['en'])->not->toBe('Hacked');
})->with(['g1', 'c2', 'b2', 'c3', 'c4']);

it('returns 404 for a guessed id', function () {
    $this->asToken($this->c1Token)->getJson('/api/organizations/'.Str::ulid())->assertNotFound();
});

it('creates a branch under the company and audits it', function () {
    $response = $this->asToken($this->c1Token)->postJson('/api/organizations', [
        'parent_id' => $this->w->c1->id,
        'type' => 'branch',
        'name' => ['en' => 'North Campus', 'bn' => 'উত্তর ক্যাম্পাস'],
    ])->assertCreated();

    $branch = Organization::findOrFail($response->json('data.id'));

    expect($branch->path)->toBe($this->w->c1->path.$branch->id.'/')
        ->and($branch->partner_id)->toBe($this->w->partnerA->id)
        ->and($response->json('data.name.bn'))->toBe('উত্তর ক্যাম্পাস');

    $audit = AuditLog::where('action', 'organization.created')->where('target_id', $branch->id)->sole();
    expect($audit->actor_user_id)->toBe($this->c1Owner->id)
        ->and($audit->organization_id)->toBe($this->w->c1->id);
});

it('cannot create under a parent outside the context', function () {
    $this->asToken($this->c1Token)->postJson('/api/organizations', [
        'parent_id' => $this->w->c2->id,
        'type' => 'branch',
        'name' => ['en' => 'Sneaky'],
    ])->assertNotFound();
});

it('rejects an invalid type for the parent with a clear message', function () {
    $this->asToken($this->c1Token)->postJson('/api/organizations', [
        'parent_id' => $this->w->c1->id,
        'type' => 'company',
        'name' => ['en' => 'Nested company'],
        'sector_key' => 'school',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_parent')
        ->assertJsonPath('message', 'A company cannot be placed under a company. Choose a different parent.');
});

it('validates input', function (array $payload, string $field) {
    $this->asToken($this->c1Token)->postJson('/api/organizations', [
        'parent_id' => $this->w->c1->id,
        'type' => 'branch',
        'name' => ['en' => 'Branch'],
        ...$payload,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing english name' => [['name' => ['bn' => 'শাখা']], 'name.en'],
    'unsupported name locale' => [['name' => ['en' => 'X', 'fr' => 'Y']], 'name'],
    'group from client area' => [['type' => 'group'], 'type'],
    'bad country' => [['country_code' => 'bangladesh'], 'country_code'],
    'bad currency' => [['currency_code' => 'taka'], 'currency_code'],
    'bad timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    'unknown field' => [['is_admin' => true], 'is_admin'],
]);

it('requires a sector for a company', function () {
    $groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);

    $this->asToken(orgToken($groupOwner, $this->w->g1))->postJson('/api/organizations', [
        'parent_id' => $this->w->g1->id,
        'type' => 'company',
        'name' => ['en' => 'New company'],
    ])->assertUnprocessable()->assertJsonValidationErrors('sector_key');
});

it('forbids non-owners from changing the tree', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);
    $token = orgToken($staff, $this->w->c1);

    $this->asToken($token)->getJson("/api/organizations/{$this->w->b1->id}")->assertOk();
    $this->asToken($token)->postJson('/api/organizations', [
        'parent_id' => $this->w->c1->id,
        'type' => 'branch',
        'name' => ['en' => 'Branch'],
    ])->assertForbidden();
    $this->asToken($token)->patchJson("/api/organizations/{$this->w->b1->id}", ['name' => ['en' => 'X']])
        ->assertForbidden();
});

it('updates own fields and audits old and new values', function () {
    $this->asToken($this->c1Token)->patchJson("/api/organizations/{$this->w->b1->id}", [
        'name' => ['en' => 'Renamed'],
        'timezone' => 'Asia/Dhaka',
    ])->assertOk()->assertJsonPath('data.name.en', 'Renamed')->assertJsonPath('data.version', 2);

    $audit = AuditLog::where('action', 'organization.updated')->sole();
    expect($audit->old_values['name'])->toBe(['en' => 'B1'])
        ->and($audit->new_values['timezone'])->toBe('Asia/Dhaka');
});

it('rejects changing organization, parent or partner through the API', function (string $field) {
    $this->asToken($this->c1Token)->patchJson("/api/organizations/{$this->w->b1->id}", [
        $field => $this->w->c2->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$field => 'Use "Move organization" instead.']);

    expect($this->w->b1->fresh()->parent_id)->toBe($this->w->c1->id);
})->with(['organization_id', 'parent_id', 'partner_id', 'root_id', 'path', 'type']);

it('does not let you suspend the organization you are working in', function () {
    $this->asToken($this->c1Token)->patchJson("/api/organizations/{$this->w->c1->id}", ['status' => 'suspended'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'own_context_status');
});

it('explains where each setting comes from', function () {
    $this->asToken($this->c1Token)->getJson("/api/organizations/{$this->w->b1->id}/settings")
        ->assertOk()
        ->assertJsonPath('data.currency_code.value', 'BDT')
        ->assertJsonPath('data.currency_code.source', 'inherited')
        ->assertJsonPath('data.currency_code.source_organization_id', $this->w->g1->id)
        // Nobody sets a language: the country's (Bangladesh: Bangla).
        ->assertJsonPath('data.default_locale.value', 'bn')
        ->assertJsonPath('data.default_locale.source', 'country');
});

it('lets a group owner move a branch between its companies', function () {
    $groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);

    $this->asToken(orgToken($groupOwner, $this->w->g1))->postJson("/api/organizations/{$this->w->b1->id}/move", [
        'new_parent_id' => $this->w->c2->id,
        'reason' => 'Campus handed over to C2',
    ])->assertOk()->assertJsonPath('data.parent_id', $this->w->c2->id);

    expect($this->w->d1->fresh()->path)->toStartWith($this->w->c2->path)
        ->and(AuditLog::where('action', 'organization.moved')->sole()->actor_user_id)->toBe($groupOwner->id);
});

it('does not let a company owner move a branch to another company', function () {
    $this->asToken($this->c1Token)->postJson("/api/organizations/{$this->w->b1->id}/move", [
        'new_parent_id' => $this->w->c2->id,
        'reason' => 'Trying to escape',
    ])->assertNotFound();

    expect($this->w->b1->fresh()->parent_id)->toBe($this->w->c1->id);
});

it('requires a reason to move', function () {
    $groupOwner = createMember($this->w->g1, MembershipType::Owner, AccessScope::Descendants);

    $this->asToken(orgToken($groupOwner, $this->w->g1))->postJson("/api/organizations/{$this->w->b1->id}/move", [
        'new_parent_id' => $this->w->c2->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('answers in Bangla when the organization uses Bangla', function () {
    $this->w->g1->update(['default_locale' => 'bn']);
    $token = orgToken(withoutOwnLanguage($this->c1Owner), $this->w->c1);

    $this->asToken($token)->getJson('/api/organizations/'.Str::ulid())
        ->assertNotFound()
        ->assertJsonPath('message', __('tenancy.errors.organization_not_found', [], 'bn'));
});
