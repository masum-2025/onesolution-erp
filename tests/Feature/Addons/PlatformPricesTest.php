<?php

use App\Platform\Addons\AddonPriceBook;
use App\Platform\Audit\AuditLog;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;

/*
 * Every module is for sale in the add-on store, at a price the platform (the
 * house partner's owner or billing staff) sets per module, currency and kind
 * (list: what clients pay; wholesale: what wholesale partners pay us). A
 * partner sets its own price for any module, priced by the platform or not.
 * A module without a price cannot be added to the code: the data file must
 * price it, or its manifest must say it is not sold.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC'));
    $this->w = tenancyWorld();
    $this->w->partnerA->forceFill(['billing_mode' => BillingMode::Direct])->save();
    setPlan($this->w->g1, 'starter');
    if (! Partner::query()->where('is_house', true)->exists()) {
        Partner::factory()->create(['name' => 'One Solutions'])->forceFill(['is_house' => true])->save();
    }
    $this->house = Partner::query()->where('is_house', true)->sole();
    $this->houseOwner = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Owner), $this->house);
    $this->platform = fn (string $module, array $body, ?string $token = null) => $this->asToken($token ?? $this->houseOwner)
        ->putJson("/api/partner/addons/{$module}/platform", ['reason' => 'New price list', ...$body]);
    $this->withHeader('Origin', config('app.url'));
});

it('prices every module the platform deploys, so each is in the add-on store', function () {
    $source = file_get_contents(database_path('seeders/data/addon-prices.php'));
    $unpriced = collect(app(ModuleRegistry::class)->all())
        ->filter(fn ($module) => $module->sellable && ! str_contains($source, "    '{$module->key}' => ["))
        ->keys()->values()->all();

    // A new module is priced in database/seeders/data/addon-prices.php (make:erp-module adds a line), or its manifest says 'addon' => ['sellable' => false].
    expect($unpriced)->toBe([]);

    // Letters, beyond a starter plan, is for sale to the account at the data file's price.
    $token = orgToken(createMember($this->w->g1, MembershipType::Owner), $this->w->g1);
    $item = collect($this->asToken($token)->getJson("/api/organizations/{$this->w->g1->id}/addons")->assertOk()->json('data.items'))->firstWhere('key', 'letters');
    expect($item)->toMatchArray(['status' => 'for_sale', 'price' => ['amount_minor' => 50000, 'currency' => 'BDT']]);
});

it('lets the platform set every module\'s list and wholesale price, and nobody else', function () {
    $page = $this->asToken($this->houseOwner)->getJson('/api/partner/addons')->assertOk()->json();
    expect($page)->toMatchArray(['can_set_platform' => true, 'can_edit' => false])
        ->and($page['currencies'])->toContain('BDT', 'USD')
        ->and(collect($page['data'])->pluck('key'))->toContain('letters', 'payroll', 'hrm')
        ->and(collect(collect($page['data'])->firstWhere('key', 'letters')['prices'])->firstWhere('currency', 'BDT'))
        ->toMatchArray(['platform_minor' => 50000, 'wholesale_minor' => 30000]);

    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => 60000])->assertOk();
    ($this->platform)('letters', ['kind' => 'wholesale', 'currency' => 'BDT', 'amount_minor' => 35000])->assertOk();
    $book = app(AddonPriceBook::class);
    expect($book->listPrice($this->w->partnerA, 'letters', 'BDT'))->toBe(60000)
        ->and($book->wholesalePrice($this->w->partnerB, 'letters', 'BDT'))->toBe(35000)
        ->and(AuditLog::query()->where('action', 'addons.price_set')->where('new_values->module', 'letters')->where('reason', 'New price list')->count())->toBe(2);

    // A new currency for one module; empty stops selling it in that currency.
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'EUR', 'amount_minor' => 450])->assertOk();
    expect($this->asToken($this->houseOwner)->getJson('/api/partner/addons')->json('currencies'))->toContain('EUR');
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'EUR', 'amount_minor' => null])->assertOk();
    expect($book->listPrice($this->w->partnerA, 'letters', 'EUR'))->toBeNull();

    // Checked: a kind, a currency code, whole minor units, a reason.
    ($this->platform)('letters', ['currency' => 'BDT', 'amount_minor' => 1])->assertUnprocessable()->assertJsonValidationErrors('kind');
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'taka', 'amount_minor' => 1])->assertUnprocessable()->assertJsonValidationErrors('currency');
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => -5])->assertUnprocessable();
    ($this->platform)('nothing_here', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => 1])->assertNotFound();

    // Other partners, and house staff other than owner or billing, cannot.
    $partnerOwner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => 1], $partnerOwner)->assertForbidden()->assertJsonPath('code', 'addon_not_house');
    $support = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Support), $this->house);
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => 1], $support)->assertForbidden();
    $billing = partnerToken(createPartnerStaff($this->house, PartnerUserRole::Billing), $this->house);
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => 55000], $billing)->assertOk();
    expect($book->platformListPrice('letters', 'BDT'))->toBe(55000);
});

it('lets a partner price any module for its clients, even one the platform does not price', function () {
    // The platform stops selling Letters in BDT; partner A still lists it and sets its own price.
    ($this->platform)('letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => null])->assertOk();
    $owner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $page = $this->asToken($owner)->getJson('/api/partner/addons')->assertOk()->json();
    $letters = collect(collect($page['data'])->firstWhere('key', 'letters')['prices'])->firstWhere('currency', 'BDT');
    expect($page)->toMatchArray(['can_edit' => true, 'can_set_platform' => false])
        ->and($letters)->toMatchArray(['platform_minor' => null, 'own_minor' => null])
        ->and($letters)->not->toHaveKey('wholesale_minor');

    $this->asToken($owner)->putJson('/api/partner/addons/letters', ['currency' => 'BDT', 'amount_minor' => 70000, 'reason' => 'Our own price'])->assertOk();
    $this->asToken($owner)->putJson('/api/partner/addons/letters', ['kind' => 'list', 'currency' => 'BDT', 'amount_minor' => 1, 'reason' => 'Our own price'])
        ->assertUnprocessable()->assertJsonValidationErrors('kind');
    $token = orgToken(createMember($this->w->g1, MembershipType::Owner), $this->w->g1);
    $item = collect($this->asToken($token)->getJson("/api/organizations/{$this->w->g1->id}/addons")->json('data.items'))->firstWhere('key', 'letters');
    expect($item)->toMatchArray(['status' => 'for_sale', 'price' => ['amount_minor' => 70000, 'currency' => 'BDT']]);

    // Partner B never sees partner A's price.
    expect(app(AddonPriceBook::class)->listPrice($this->w->partnerB, 'letters', 'BDT'))->toBeNull();
});
