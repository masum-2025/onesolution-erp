<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-only demo tree under the house partner:
 * Demo Group > Demo School > Main Campus > Science Department,
 * with a group owner and a company owner. Passwords are random and printed once.
 */
class DemoHierarchySeeder extends Seeder
{
    public function run(CreateOrganization $create, AddMember $addMember): void
    {
        $partner = Partner::query()->where('is_house', true)->firstOrFail();

        if ($partner->organizations()->exists()) {
            $this->command?->warn('Demo hierarchy skipped: the house partner already has organizations.');

            return;
        }

        $group = $create->handle(OrganizationType::Group, [
            'name' => ['en' => 'Demo Group', 'bn' => 'ডেমো গ্রুপ'],
            'country_code' => 'BD',
            'default_locale' => 'bn',
            'timezone' => 'Asia/Dhaka',
            'currency_code' => 'BDT',
            'region' => 'bd',
        ], partner: $partner);

        $company = $create->handle(OrganizationType::Company, [
            'name' => ['en' => 'Demo School', 'bn' => 'ডেমো স্কুল'],
            'sector_key' => 'school',
        ], parent: $group);

        $branch = $create->handle(OrganizationType::Branch, [
            'name' => ['en' => 'Main Campus', 'bn' => 'প্রধান ক্যাম্পাস'],
        ], parent: $company);

        $create->handle(OrganizationType::Department, [
            'name' => ['en' => 'Science Department', 'bn' => 'বিজ্ঞান বিভাগ'],
        ], parent: $branch);

        $groupOwner = $this->demoUser('Group Owner', 'group.owner@demo.test');
        $addMember->handle($group, $groupOwner, MembershipType::Owner, AccessScope::Descendants);

        $companyOwner = $this->demoUser('School Owner', 'school.owner@demo.test');
        $addMember->handle($company, $companyOwner, MembershipType::Owner);
    }

    private function demoUser(string $name, string $email): User
    {
        $password = Str::password(16);

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->command?->info("Demo login: {$email} / {$password}");

        return $user;
    }
}
