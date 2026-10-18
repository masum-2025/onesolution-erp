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

it('lets each person pick a window style, or the company lock one for everyone', function () {
    // Default: the centred box, all three styles open.
    expect(myLook($this, $this->token)['dialog'])->toMatchArray(['value' => 'center', 'source' => 'default', 'locked' => false, 'allowed' => ['center', 'panel', 'premium']]);

    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog' => 'panel'])->assertOk()->assertJsonPath('data.dialog', 'panel');
    expect(myLook($this, $this->token)['dialog'])->toMatchArray(['value' => 'panel', 'source' => 'user', 'own' => 'panel']);
    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog' => 'sideways'])->assertUnprocessable()->assertJsonValidationErrors(['dialog']);

    // A company lock wins over the person's own choice, and says where it comes from.
    orgRule($this->w->c1, 'ui.dialog_style', 'premium', RuleMode::Lock);
    expect(myLook($this, $this->token)['dialog'])->toMatchArray(['value' => 'premium', 'locked' => true, 'allowed' => ['premium'], 'name' => 'C1', 'own' => 'panel']);

    // Cleared, it follows the organization again.
    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog' => null])->assertOk();
    expect(myLook($this, $this->token)['dialog']['own'])->toBeNull();
});

it('offers form-colour templates, brand tint first, and keeps the person\'s pick', function () {
    expect(myLook($this, $this->token)['dialog_tone'])->toMatchArray([
        'value' => 'brand_wash', 'source' => 'default', 'locked' => false, 'allowed' => ['brand_wash', 'soft', 'grey_card', 'mint', 'plain'],
    ]);

    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog_tone' => 'plain'])->assertOk()->assertJsonPath('data.dialog_tone', 'plain');
    expect(myLook($this, $this->token)['dialog_tone'])->toMatchArray(['value' => 'plain', 'source' => 'user', 'own' => 'plain']);

    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog_tone' => 'neon'])->assertUnprocessable()->assertJsonValidationErrors(['dialog_tone']);
});

it('lets a level above turn form-colour templates off', function () {
    $this->person->forceFill(['ui_preferences' => ['dialog_tone' => 'plain']])->save();
    partnerRule($this->w->partnerA, 'ui.dialog_tone', ['allowed' => ['brand_wash', 'soft']], RuleMode::Constrain);

    // A template turned off is not offered, and a pick of it gives way to the organization's.
    expect(myLook($this, $this->token)['dialog_tone'])->toMatchArray(['value' => 'brand_wash', 'own' => 'plain', 'allowed' => ['brand_wash', 'soft']]);

    // A template still on stays the person's to pick.
    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog_tone' => 'soft'])->assertOk();
    expect(myLook($this, $this->token)['dialog_tone'])->toMatchArray(['value' => 'soft', 'source' => 'user']);

    // Another partner's clients keep every template.
    $elsewhere = createMember($this->w->c4, MembershipType::Staff);
    $elsewhere->forceFill(['ui_preferences' => ['dialog_tone' => 'plain']])->save();
    expect(myLook($this, orgToken($elsewhere, $this->w->c4))['dialog_tone'])->toMatchArray(['value' => 'plain', 'allowed' => ['brand_wash', 'soft', 'grey_card', 'mint', 'plain']]);
});

it('lets a company lock one form-colour template for everyone in it', function () {
    $this->person->forceFill(['ui_preferences' => ['dialog_tone' => 'plain']])->save();
    orgRule($this->w->c1, 'ui.dialog_tone', 'soft', RuleMode::Lock);

    expect(myLook($this, $this->token)['dialog_tone'])->toMatchArray(['value' => 'soft', 'locked' => true, 'allowed' => ['soft'], 'name' => 'C1', 'own' => 'plain']);

    // Its sister company is not touched.
    $elsewhere = createMember($this->w->c2, MembershipType::Staff);
    expect(myLook($this, orgToken($elsewhere, $this->w->c2))['dialog_tone'])->toMatchArray(['value' => 'brand_wash', 'locked' => false]);
});

it('offers page-colour templates for header and body, each turned on or off above', function () {
    expect(myLook($this, $this->token)['page_tone'])->toMatchArray([
        'value' => 'soft', 'source' => 'default', 'allowed' => ['soft', 'white', 'slate', 'brand_wash', 'brand_header', 'mint'],
    ]);

    $this->asToken($this->token)->putJson('/api/me/appearance', ['page_tone' => 'mint'])->assertOk()->assertJsonPath('data.page_tone', 'mint');
    expect(myLook($this, $this->token)['page_tone'])->toMatchArray(['value' => 'mint', 'source' => 'user']);
    $this->asToken($this->token)->putJson('/api/me/appearance', ['page_tone' => 'neon'])->assertUnprocessable()->assertJsonValidationErrors(['page_tone']);

    // The company turns green off: the person's pick gives way, the others stay open.
    orgRule($this->w->c1, 'ui.page_tone', ['allowed' => ['soft', 'white']], RuleMode::Constrain);
    expect(myLook($this, $this->token)['page_tone'])->toMatchArray(['value' => 'soft', 'own' => 'mint', 'allowed' => ['soft', 'white']]);

    // Its sister company is not touched.
    $elsewhere = createMember($this->w->c2, MembershipType::Staff);
    $elsewhere->forceFill(['ui_preferences' => ['page_tone' => 'mint']])->save();
    expect(myLook($this, orgToken($elsewhere, $this->w->c2))['page_tone'])->toMatchArray(['value' => 'mint', 'locked' => false]);
});

it('shows the brand band in side-panel forms unless turned off, by the person or a lock above', function () {
    expect(myLook($this, $this->token)['dialog_band'])->toMatchArray(['value' => 'on', 'source' => 'default', 'allowed' => ['on', 'off']]);

    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog_band' => 'off'])->assertOk()->assertJsonPath('data.dialog_band', 'off');
    expect(myLook($this, $this->token)['dialog_band'])->toMatchArray(['value' => 'off', 'source' => 'user']);
    $this->asToken($this->token)->putJson('/api/me/appearance', ['dialog_band' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['dialog_band']);

    orgRule($this->w->c1, 'ui.dialog_band', 'on', RuleMode::Lock);
    expect(myLook($this, $this->token)['dialog_band'])->toMatchArray(['value' => 'on', 'locked' => true, 'own' => 'off']);

    $elsewhere = createMember($this->w->c2, MembershipType::Staff);
    $elsewhere->forceFill(['ui_preferences' => ['dialog_band' => 'off']])->save();
    expect(myLook($this, orgToken($elsewhere, $this->w->c2))['dialog_band'])->toMatchArray(['value' => 'off', 'locked' => false]);
});
