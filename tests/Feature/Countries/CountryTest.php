<?php

use App\Models\User;
use App\Platform\Countries\CountryCatalog;
use App\Platform\Countries\Models\Country;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\Models\RuleValueHistory;
use App\Platform\Support\LocaleResolver;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Database\Seeders\CountriesSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Multi-country and multi-language (Phase 6). The same code runs a
 * Bangladesh school (BDT, Bangla, Friday weekend, July fiscal year) and a
 * Saudi company (SAR, Arabic right to left, Friday-Saturday weekend): every
 * difference comes from the country data files and the rules they feed.
 */

beforeEach(function () {
    $this->partner = Partner::factory()->create(['name' => 'Partner A']);
    $this->seed(CountriesSeeder::class);
});

/** A group in a country with one company under it; nothing else set. */
function companyIn(object $test, string $country, string $sector = 'school'): object
{
    $group = createGroup($test->partner, "Group {$country}", ['country_code' => $country]);
    $company = createChild($group, OrganizationType::Company, "Company {$country}", ['sector_key' => $sector]);
    $owner = withoutOwnLanguage(createMember($company, MembershipType::Owner));

    return (object) compact('group', 'company', 'owner');
}

function settingsOf($organization): array
{
    return app(OrganizationSettingsResolver::class)->explain($organization->fresh());
}

it('runs a Bangladesh school: BDT, Bangla, Asia/Dhaka, Friday weekend, July fiscal year', function () {
    $bd = companyIn($this, 'BD');
    $settings = settingsOf($bd->company);

    expect($settings['currency_code'])->toMatchArray(['value' => 'BDT', 'source' => 'country'])
        ->and($settings['default_locale'])->toMatchArray(['value' => 'bn', 'source' => 'country'])
        ->and($settings['timezone']['value'])->toBe('Asia/Dhaka')
        ->and($settings['region']['value'])->toBe('bd')
        ->and(ruleFor('attendance.weekend_days', $bd->company))->toBe(['fri'])
        ->and(ruleFor('accounting.fiscal_year_start', $bd->company))->toBe('07-01')
        ->and(ruleFor('regional.week_start', $bd->company))->toBe('sat')
        ->and(ruleFor('regional.date_format', $bd->company))->toBe('DD/MM/YYYY')
        ->and(ruleFor('billing.payment_gateways', $bd->company))->toBe(['sslcommerz']);

    // A person who never picked a language reads the school's: Bangla.
    $this->asToken(orgToken($bd->owner, $bd->company))->getJson('/api/organizations/'.Str::ulid())
        ->assertNotFound()->assertJsonPath('message', __('tenancy.errors.organization_not_found', [], 'bn'));
});

it('runs a Saudi company with the same code: SAR, Asia/Riyadh, Friday-Saturday weekend', function () {
    $sa = companyIn($this, 'SA', 'general');
    $settings = settingsOf($sa->company);

    // Arabic texts are not shipped yet: the country's next language (English) is used.
    expect($settings['currency_code']['value'])->toBe('SAR')
        ->and($settings['default_locale']['value'])->toBe('en')
        ->and($settings['timezone']['value'])->toBe('Asia/Riyadh')
        ->and($settings['region']['value'])->toBe('me')
        ->and(ruleFor('attendance.weekend_days', $sa->company))->toBe(['fri', 'sat'])
        ->and(ruleFor('accounting.fiscal_year_start', $sa->company))->toBe('01-01')
        ->and(ruleFor('regional.week_start', $sa->company))->toBe('sun')
        ->and(ruleFor('billing.payment_gateways', $sa->company))->toBe([])
        ->and(LocaleResolver::direction('ar'))->toBe('rtl')
        ->and(LocaleResolver::direction('bn'))->toBe('ltr');

    $this->asToken(orgToken($sa->owner, $sa->company))->getJson('/api/organizations/'.Str::ulid())
        ->assertNotFound()->assertJsonPath('message', __('tenancy.errors.organization_not_found', [], 'en'));
});

it('speaks Arabic, right to left, as soon as Arabic texts are turned on (data, not code)', function () {
    config(['tenancy.supported_locales' => ['en', 'bn', 'ar']]);
    $sa = companyIn($this, 'SA', 'general');

    expect(settingsOf($sa->company)['default_locale'])->toMatchArray(['value' => 'ar', 'source' => 'country'])
        ->and(app(LocaleResolver::class)->resolve(null, null, $sa->company))->toBe('ar');

    // The shell tells the browser which languages are right to left.
    $this->get('/')->assertOk()->assertSee('dir="ltr"', false)->assertSee('["ar"]', false);
});

it('lets a company override what its country brings, and keeps the rest', function () {
    $sa = companyIn($this, 'SA', 'general');
    $sa->company->update(['default_locale' => 'en', 'timezone' => 'Asia/Dubai']);
    toggles()->enable($sa->group, 'attendance', 'Test setup');
    orgRule($sa->company, 'attendance.weekend_days', ['fri']);

    $settings = settingsOf($sa->company);
    expect($settings['default_locale'])->toMatchArray(['value' => 'en', 'source' => 'self'])
        ->and($settings['timezone']['value'])->toBe('Asia/Dubai')
        ->and($settings['currency_code'])->toMatchArray(['value' => 'SAR', 'source' => 'country'])
        ->and(ruleFor('attendance.weekend_days', $sa->company))->toBe(['fri']);
});

it('adds a new country with a data file only', function () {
    $directory = storage_path('framework/testing/countries-'.Str::random(6));
    File::copyDirectory(database_path('data/countries'), $directory);
    File::put($directory.'/NZ.php', <<<'PHP'
<?php

return [
    'code' => 'NZ',
    'name' => ['en' => 'New Zealand', 'bn' => 'নিউজিল্যান্ড', 'ar' => 'نيوزيلندا'],
    'currency' => 'NZD',
    'currency_decimals' => 2,
    'date_format' => 'DD/MM/YYYY',
    'week_start' => 'mon',
    'weekend_days' => ['sat', 'sun'],
    'fiscal_year_start' => '04-01',
    'phone' => ['dial' => '64', 'trunk' => '0', 'national' => '2\d{7,9}', 'example' => '0211234567'],
    'address_format' => ['line1', 'suburb', 'city', 'postcode'],
    'tax_profile' => 'nz_gst',
    'data_residency_region' => 'au',
    'default_locale' => 'en',
    'locales' => ['en'],
    'timezone' => 'Pacific/Auckland',
    'payment_gateways' => [],
    'merchant_gateways' => [],
];
PHP);

    try {
        config(['countries.path' => $directory]);
        app()->forgetInstance(CountryCatalog::class);
        $this->artisan('countries:sync')->assertSuccessful();

        $owner = createMember($group = createGroup($this->partner, 'Kiwi Group'), MembershipType::Owner);
        $this->asToken(orgToken($owner, $group))->patchJson("/api/organizations/{$group->id}", ['country_code' => 'NZ'])->assertOk();
        $company = createChild($group, OrganizationType::Company, 'Kiwi School');

        expect(settingsOf($company)['currency_code']['value'])->toBe('NZD')
            ->and(settingsOf($company)['timezone']['value'])->toBe('Pacific/Auckland')
            ->and(ruleFor('accounting.fiscal_year_start', $company))->toBe('04-01')
            ->and(PhoneNumber::normalize('021 123 4567', 'NZ'))->toBe('+64211234567')
            ->and(Country::query()->where('code', 'NZ')->first()->texts('name')['ar'])->toBe('نيوزيلندا');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a country it has no data for', function () {
    $owner = createMember($group = createGroup($this->partner, 'Somewhere'), MembershipType::Owner);

    $this->asToken(orgToken($owner, $group))->patchJson("/api/organizations/{$group->id}", ['country_code' => 'ZZ'])
        ->assertUnprocessable()->assertJsonValidationErrors(['country_code']);
});

it('syncs again without changing anything, and audits what it writes', function () {
    $history = RuleValueHistory::query()->count();
    expect($history)->toBeGreaterThan(0)
        ->and(RuleValueHistory::query()->where('reason', 'Country data file SA.php')->exists())->toBeTrue();

    $this->artisan('countries:sync')->expectsOutputToContain('0 rule values written')->assertSuccessful();

    expect(RuleValueHistory::query()->count())->toBe($history)
        ->and(Country::query()->count())->toBe(count(app(CountryCatalog::class)->all()));
});

it('reads phone numbers by the country data', function () {
    expect(PhoneNumber::normalize('0512345678', 'SA'))->toBe('+966512345678')
        ->and(PhoneNumber::normalize('01712345678', 'BD'))->toBe('+8801712345678')
        ->and(PhoneNumber::country('+966512345678'))->toBe('SA')
        ->and(PhoneNumber::normalize('0512345678', 'BD'))->toBeNull();
});

it('lists countries in the reader\'s language', function () {
    $bd = companyIn($this, 'BD');

    $this->asToken(orgToken($bd->owner, $bd->company))->withHeader('X-Locale', 'bn')->getJson('/api/countries')
        ->assertOk()->assertJsonFragment(['code' => 'BD', 'name' => 'বাংলাদেশ', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    $this->asToken(orgToken($bd->owner, $bd->company))->withHeader('X-Locale', 'en')->getJson('/api/countries')
        ->assertOk()->assertJsonFragment(['code' => 'SA', 'name' => 'Saudi Arabia', 'default_locale' => 'ar']);
});

it('lists countries in the partner console too (the new-client form)', function () {
    $this->asToken(partnerToken(createPartnerStaff($this->partner, PartnerUserRole::Owner), $this->partner))->getJson('/api/countries')
        ->assertOk()->assertJsonFragment(['code' => 'BD', 'currency' => 'BDT']);
});

it('lists countries only to someone signed in', function () {
    $this->getJson('/api/countries')->assertUnauthorized();
});

it('writes to each person in their own language, else the organization\'s', function () {
    $sa = companyIn($this, 'SA', 'general');
    $bangla = User::factory()->create(['locale' => 'bn']);
    app(AddMember::class)->handle($sa->company, $bangla, MembershipType::Owner, AccessScope::Own);

    app(Notifier::class)->notify('members.added', [$sa->owner, $bangla], fn (string $locale) => ['organization' => 'Co', 'inviter' => 'X'], $this->partner, $sa->company);

    expect(NotificationDelivery::query()->where('user_id', $sa->owner->id)->value('locale'))->toBe('en')
        ->and(NotificationDelivery::query()->where('user_id', $bangla->id)->value('locale'))->toBe('bn');
});

it('keeps a person\'s own timezone and language before the organization\'s', function () {
    $sa = companyIn($this, 'SA', 'general');
    $token = orgToken($sa->owner, $sa->company);

    $this->asToken($token)->patchJson('/api/me/account', ['timezone' => 'Asia/Dhaka', 'locale' => 'bn'])->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Dhaka');
    $this->asToken($token)->patchJson('/api/me/account', ['timezone' => 'Mars/Olympus'])->assertUnprocessable();

    $this->asToken(orgToken($sa->owner->fresh(), $sa->company))->getJson('/api/me')
        ->assertOk()->assertJsonPath('data.user.timezone', 'Asia/Dhaka')->assertJsonPath('data.user.locale', 'bn')
        ->assertJsonPath('data.context.settings.timezone', 'Asia/Riyadh');

    $resolver = app(LocaleResolver::class);
    expect($resolver->resolve(null, $sa->owner->fresh(), $sa->company))->toBe('bn')
        ->and($resolver->resolve('en', $sa->owner->fresh(), $sa->company))->toBe('en')
        ->and($resolver->timezone($sa->owner->fresh(), $sa->company))->toBe('Asia/Dhaka')
        ->and($resolver->resolve(null, null, $sa->company))->toBe('en');
});

it('keeps country settings inside the client: another partner sees nothing', function () {
    $bd = companyIn($this, 'BD');
    $other = createGroup(Partner::factory()->create(['name' => 'Partner B']), 'Other', ['country_code' => 'SA']);
    $outsider = createMember($other, MembershipType::Owner);

    $this->asToken(orgToken($outsider, $other))->getJson("/api/organizations/{$bd->company->id}/settings")->assertNotFound();
});

it('names data labels in every language, falling back when one is missing', function () {
    $bd = companyIn($this, 'BD');
    $bd->company->putTexts('name', ['en' => 'Sunrise School', 'bn' => 'সানরাইজ স্কুল'])->save();
    $company = $bd->company->fresh();

    expect($company->displayName('bn'))->toBe('সানরাইজ স্কুল')
        ->and($company->displayName('ar'))->toBe('Sunrise School')
        ->and($company->texts('name'))->toEqualCanonicalizing(['en' => 'Sunrise School', 'bn' => 'সানরাইজ স্কুল']);

    $company->putTexts('name', ['en' => 'Sunrise', 'ar' => 'شروق'])->save();
    expect($company->fresh()->texts('name'))->toEqualCanonicalizing(['en' => 'Sunrise', 'ar' => 'شروق']);
});
