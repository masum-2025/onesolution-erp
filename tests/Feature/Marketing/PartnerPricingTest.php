<?php

use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Site;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\HostResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/*
 * A partner's pricing section shows the partner's own plans (names and
 * prices from its partner plans, never typed in the section), only its
 * active ones, and never another partner's.
 */

beforeEach(function () {
    config(['marketing.hosts' => 'marketing.test', 'marketing.url' => '']);
    RateLimiter::clear('marketing:127.0.0.1');
    $this->w = tenancyWorld();
    $this->ownerA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->ownerB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $this->asToken($this->ownerA)->postJson('/api/partner/website')->assertCreated();
    $this->homeA = Page::query()->where('site_id', Site::query()->where('partner_id', $this->w->partnerA->id)->value('id'))->where('is_home', true)->value('id');
    PartnerDomain::create(['partner_id' => $this->w->partnerA->id, 'host' => 'www.partner-a.test', 'serves' => 'website', 'status' => 'active', 'verification_token' => 'x', 'verified_at' => now()]);
    app(HostResolver::class)->forget('www.partner-a.test');
});

function partnerPlanFor(object $test, string $token, string $name, int $monthly, ?int $yearly = null): string
{
    return $test->asToken($token)->postJson('/api/partner/plans', [
        'base_plan_key' => 'business',
        'name' => ['en' => $name, 'bn' => $name.' বাংলা'],
        'prices' => array_values(array_filter([
            ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => $monthly],
            $yearly === null ? null : ['currency' => 'BDT', 'period' => 'yearly', 'amount_minor' => $yearly],
        ])),
    ])->assertCreated()->json('data.id');
}

function addPricing(object $test, array $plans, ?string $highlight = null)
{
    return $test->asToken($test->ownerA)->postJson("/api/partner/website/pages/{$test->homeA}/blocks", [
        'type' => 'pricing', 'data' => ['title' => ['en' => 'Our plans'], 'plans' => $plans, 'highlight' => $highlight, 'currency' => 'BDT'],
    ]);
}

function pricingPage(object $test): string
{
    $content = $test->get('http://www.partner-a.test/')->assertOk()->getContent();
    URL::forceRootUrl(config('app.url'));

    return $content;
}

it('offers the partner its own active plans by name in the pricing form', function () {
    $school = partnerPlanFor($this, $this->ownerA, 'School Plus', 700000, 7000000);
    partnerPlanFor($this, $this->ownerB, 'Shop Basic', 300000);

    $choices = $this->asToken($this->ownerA)->getJson('/api/partner/website')->assertOk()->json('data.choices');
    expect($choices['block_types'])->toContain('pricing')
        ->and($choices['plans'])->toBe([$school])
        ->and($choices['plan_names'])->toBe([$school => 'School Plus'])
        ->and(collect($choices['fields']['pricing'])->firstWhere('key', 'plans')['item']['options'])->toBe([$school]);
});

it('shows the partner\'s plans with their own names and prices on its website', function () {
    $school = partnerPlanFor($this, $this->ownerA, 'School Plus', 700000, 7000000);
    $college = partnerPlanFor($this, $this->ownerA, 'College', 1200000);
    addPricing($this, [$school, $college], $school)->assertCreated();
    $this->asToken($this->ownerA)->postJson('/api/partner/website/publish')->assertOk();

    $page = pricingPage($this);
    expect($page)->toContain('School Plus')->toContain('College')->toContain('৳7,000')->toContain('৳70,000')->toContain('৳12,000')
        // Talked over first: the button opens the contact form, never the platform's sign-up for a plan.
        ->toContain('href="#write-to-us"')->not->toContain('signup?plan=');
});

it('drops an archived plan from the website and refuses it in new sections', function () {
    $school = partnerPlanFor($this, $this->ownerA, 'School Plus', 700000);
    $college = partnerPlanFor($this, $this->ownerA, 'College', 1200000);
    addPricing($this, [$school, $college])->assertCreated();
    $this->asToken($this->ownerA)->postJson('/api/partner/website/publish')->assertOk();

    $this->asToken($this->ownerA)->postJson("/api/partner/plans/{$college}/archive", ['reason' => 'No longer offered'])->assertOk();
    \Illuminate\Support\Facades\Cache::flush();

    expect(pricingPage($this))->toContain('School Plus')->not->toContain('College');
    addPricing($this, [$college])->assertUnprocessable()->assertJsonValidationErrors('data.plans.0');
    expect(PartnerPlan::query()->find($college)->status)->toBe(PartnerPlan::ARCHIVED);
});

it('never lets a partner name or show another partner\'s plan', function () {
    $other = partnerPlanFor($this, $this->ownerB, 'Shop Basic', 300000);

    addPricing($this, [$other])->assertUnprocessable()->assertJsonValidationErrors('data.plans.0');
    addPricing($this, ['starter'])->assertUnprocessable()->assertJsonValidationErrors('data.plans.0');

    $own = partnerPlanFor($this, $this->ownerA, 'School Plus', 700000);
    addPricing($this, [$own], $other)->assertUnprocessable()->assertJsonValidationErrors('data.highlight');
});
