<?php

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Enums\MembershipType;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->person = createMember($this->w->c1, MembershipType::Staff);
    $this->token = orgToken($this->person, $this->w->c1);
});

function myLook(object $test, string $token): array
{
    return $test->asToken($token)->getJson('/api/me')->assertOk()->json('data.appearance');
}

it('starts from the platform default with every choice open', function () {
    $look = myLook($this, $this->token);

    expect($look['template'])->toBe([
        'value' => 'classic', 'source' => 'default', 'level' => null, 'name' => null,
        'locked' => false, 'own' => null, 'allowed' => ['classic', 'light'],
    ])
        ->and($look['accent']['value'])->toBe('brand')
        ->and($look['accent']['allowed'])->toHaveCount(9)
        ->and($look['color_vision'])->toBe('standard')
        ->and($look['contrast'])->toBe('standard');
});

it('keeps the person\'s own look', function () {
    $this->asToken($this->token)->putJson('/api/me/appearance', [
        'template' => 'light', 'accent' => 'teal', 'color_vision' => 'blue_orange', 'contrast' => 'high',
    ])->assertOk()
        ->assertJsonPath('data', ['template' => 'light', 'accent' => 'teal', 'color_vision' => 'blue_orange', 'contrast' => 'high'])
        ->assertJsonPath('message', 'Your look is saved.');

    $look = myLook($this, $this->token);

    expect($look['template']['value'])->toBe('light')
        ->and($look['template']['source'])->toBe('user')
        ->and($look['accent']['value'])->toBe('teal')
        ->and($look['color_vision'])->toBe('blue_orange')
        ->and($look['contrast'])->toBe('high');
});

it('lets a company lock the look for everyone in it', function () {
    $this->person->forceFill(['ui_preferences' => ['template' => 'light', 'accent' => 'rose']])->save();
    orgRule($this->w->c1, 'ui.shell_template', 'classic', RuleMode::Lock);

    $look = myLook($this, $this->token);

    expect($look['template'])->toMatchArray([
        'value' => 'classic', 'source' => 'organization', 'level' => 'company', 'name' => 'C1',
        'locked' => true, 'own' => 'light', 'allowed' => ['classic'],
    ])
        // Only the locked choice is decided for them.
        ->and($look['accent']['value'])->toBe('rose');
});

it('applies a company lock to its branches too', function () {
    $this->person->forceFill(['ui_preferences' => ['template' => 'light']])->save();
    orgRule($this->w->c1, 'ui.shell_template', 'classic', RuleMode::Lock);
    $branchPerson = createMember($this->w->b1, MembershipType::Staff);
    $branchPerson->forceFill(['ui_preferences' => ['template' => 'light']])->save();

    $look = myLook($this, orgToken($branchPerson, $this->w->b1));

    expect($look['template']['value'])->toBe('classic')
        ->and($look['template']['locked'])->toBeTrue()
        ->and($look['template']['name'])->toBe('C1');
});

it('keeps the person\'s choice where nobody locked it', function () {
    orgRule($this->w->c1, 'ui.shell_template', 'classic', RuleMode::Lock);
    $elsewhere = createMember($this->w->c2, MembershipType::Staff);
    $elsewhere->forceFill(['ui_preferences' => ['template' => 'light']])->save();

    expect(myLook($this, orgToken($elsewhere, $this->w->c2))['template'])
        ->toMatchArray(['value' => 'light', 'source' => 'user', 'locked' => false]);
});

it('uses the organization\'s value until the person picks their own', function () {
    orgRule($this->w->c1, 'ui.shell_template', 'light');

    expect(myLook($this, $this->token)['template'])
        ->toMatchArray(['value' => 'light', 'source' => 'organization', 'level' => 'company', 'locked' => false]);

    $this->person->forceFill(['ui_preferences' => ['template' => 'classic']])->save();

    expect(myLook($this, $this->token)['template'])->toMatchArray(['value' => 'classic', 'source' => 'user']);
});

it('keeps a choice inside what the partner allows', function () {
    $this->person->forceFill(['ui_preferences' => ['accent' => 'rose']])->save();
    partnerRule($this->w->partnerA, 'ui.accent', ['allowed' => ['brand', 'blue']], RuleMode::Constrain);

    $accent = myLook($this, $this->token)['accent'];

    expect($accent['value'])->toBe('brand')
        ->and($accent['own'])->toBe('rose')
        ->and($accent['allowed'])->toBe(['brand', 'blue']);
});

it('never lets an organization take away readability settings', function () {
    $this->person->forceFill(['ui_preferences' => ['color_vision' => 'blue_orange', 'contrast' => 'high']])->save();
    orgRule($this->w->c1, 'ui.shell_template', 'classic', RuleMode::Lock);
    orgRule($this->w->c1, 'ui.accent', 'brand', RuleMode::Lock);

    $look = myLook($this, $this->token);

    expect($look['color_vision'])->toBe('blue_orange')->and($look['contrast'])->toBe('high');
});

it('clears a choice with null', function () {
    $this->person->forceFill(['ui_preferences' => ['template' => 'light', 'accent' => 'teal']])->save();

    $this->asToken($this->token)->putJson('/api/me/appearance', ['template' => null])
        ->assertOk()
        ->assertJsonPath('data', ['accent' => 'teal']);

    expect($this->person->fresh()->ui_preferences)->toBe(['accent' => 'teal']);
});

it('refuses values and fields it does not know', function (array $body, string $field) {
    $this->asToken($this->token)->putJson('/api/me/appearance', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown template' => [['template' => 'neon'], 'template'],
    'unknown accent' => [['accent' => '#ff0000'], 'accent'],
    'unknown colour vision' => [['color_vision' => 'red_green'], 'color_vision'],
    'not a string' => [['contrast' => ['high']], 'contrast'],
    'unknown field' => [['template' => 'light', 'organization_id' => 'x'], 'organization_id'],
]);

it('changes only the person\'s own look', function () {
    $colleague = createMember($this->w->c1, MembershipType::Staff);

    $this->asToken($this->token)->putJson('/api/me/appearance', ['template' => 'light'])->assertOk();

    expect(myLook($this, orgToken($colleague, $this->w->c1))['template']['value'])->toBe('classic')
        ->and($colleague->fresh()->ui_preferences)->toBeNull();
});

it('needs a signed-in person', function () {
    $this->putJson('/api/me/appearance', ['template' => 'light'])->assertUnauthorized();
});
