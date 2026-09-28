<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Branding\Models\ClientBrand;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerBrand;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\PartnerClientService;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-only: a white-label partner "Acme Solutions" at erp.acme.localhost
 * with one client, "Sunrise School", that has its own address
 * (sunrise.acme.localhost) and its own sub-brand. Only adds data; skips
 * when Acme already exists, so it is safe to run on a database in use.
 */
class DemoPartnerSeeder extends Seeder
{
    public function run(ContextResolver $contexts, PartnerClientService $clients, RuleService $rules, RuleTargets $targets): void
    {
        if (Partner::query()->where('slug', 'acme')->exists()) {
            return;
        }

        $acme = Partner::query()->create(['name' => 'Acme Solutions', 'slug' => 'acme', 'status' => 'active', 'is_house' => false, 'billing_mode' => 'wholesale']);
        PartnerBrand::query()->create([
            'partner_id' => $acme->id,
            'product_name' => 'Acme ERP',
            'primary_color' => '#0F766E',
            'tagline' => ['en' => 'Schools run better', 'bn' => 'স্কুল চলুক আরও ভালো'],
            'login_title' => ['en' => 'Welcome to Acme ERP', 'bn' => 'Acme ERP-তে স্বাগতম'],
            'login_text' => ['en' => 'Sign in with the account your school gave you.', 'bn' => 'আপনার স্কুলের দেওয়া অ্যাকাউন্ট দিয়ে সাইন ইন করুন।'],
            'footer_text' => ['en' => 'Acme Solutions Ltd, Dhaka (demo)'],
            'version' => 1,
        ]);
        PartnerDomain::query()->create(['partner_id' => $acme->id, 'host' => 'erp.acme.localhost', 'status' => DomainStatus::Active, 'verification_token' => Str::random(40), 'verified_at' => now()]);

        $owner = $this->demoUser('Acme Owner', 'owner@acme.test');
        PartnerUser::query()->create(['partner_id' => $acme->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);

        // Acme lets its clients show their own brand.
        $rules->set($targets->partner($acme), 'branding.client_sub_brands_allowed', RuleMode::Set, true, 'Demo data', trusted: true);

        $head = $this->demoUser('Sunrise Head Teacher', 'head@sunrise.test');
        $contexts->enterPartner($owner, $acme->id);
        $sunrise = $clients->create($acme, ['structure' => 'group', 'name' => ['en' => 'Sunrise School', 'bn' => 'সানরাইজ স্কুল'], 'sector_key' => 'school', 'plan' => 'business', 'country_code' => 'BD'], $head, $owner)['client'];

        PartnerDomain::query()->create(['partner_id' => $acme->id, 'organization_id' => $sunrise->id, 'host' => 'sunrise.acme.localhost', 'status' => DomainStatus::Active, 'verification_token' => Str::random(40), 'verified_at' => now()]);
        (new ClientBrand)->forceFill(['organization_id' => $sunrise->id, 'display_name' => 'Sunrise Smart School', 'primary_color' => '#B45309', 'version' => 1, 'updated_by' => $head->id])->save();

        app(CurrentContext::class)->clear();
        $this->command?->info('Acme: http://erp.acme.localhost:8000 · Sunrise: http://sunrise.acme.localhost:8000');
    }

    private function demoUser(string $name, string $email): User
    {
        $password = Str::password(16);
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        $this->command?->info("Demo login: {$email} / {$password}");

        return $user;
    }
}
