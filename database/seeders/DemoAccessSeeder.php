<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Services\RoleService;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-only: the demo school's owner clones four school roles from the
 * templates and gives two of them to a teacher and an accountant. Goes through
 * RoleService, so the same anti-escalation and audit rules apply.
 */
class DemoAccessSeeder extends Seeder
{
    public function run(ContextResolver $contexts, RoleService $roles, AddMember $addMember): void
    {
        $partner = Partner::query()->where('is_house', true)->firstOrFail();
        $company = Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Company)->first();
        $branch = Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Branch)->first();
        $owner = User::query()->where('email', 'school.owner@demo.test')->first();

        if ($company === null || $branch === null || $owner === null || Role::query()->where('organization_id', $company->id)->exists()) {
            return;
        }

        $contexts->enterOrganization($owner, $company->id);

        $made = [];
        foreach (['principal', 'teacher', 'accountant', 'finance_approver'] as $template) {
            $name = ['en' => __("access.templates.{$template}.name", [], 'en'), 'bn' => __("access.templates.{$template}.name", [], 'bn')];
            $made[$template] = $roles->create($company, $name, null, null, $template, $owner, 'Demo data');
        }

        $teacher = $this->demoUser('Demo Teacher', 'teacher@demo.test');
        $addMember->handle($branch, $teacher, MembershipType::Staff, actor: $owner);
        $roles->syncMembershipRoles($branch->memberships()->where('user_id', $teacher->id)->sole(), [$made['teacher']->id], $owner, 'Demo data');

        $accountant = $this->demoUser('Demo Accountant', 'accountant@demo.test');
        $addMember->handle($company, $accountant, MembershipType::Staff, actor: $owner);
        $roles->syncMembershipRoles($company->memberships()->where('user_id', $accountant->id)->sole(), [$made['accountant']->id], $owner, 'Demo data');

        app(CurrentContext::class)->clear();
        app(AccessResolver::class)->forget();
    }

    private function demoUser(string $name, string $email): User
    {
        $password = Str::password(16);
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        $this->command?->info("Demo login: {$email} / {$password}");

        return $user;
    }
}
