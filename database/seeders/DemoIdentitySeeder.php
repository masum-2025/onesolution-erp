<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Identity\Services\PersonalWorkspaces;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-only: self-serve sign-up to try (Phase 5C). SMS on for the house
 * partner (the log driver sends nothing), and one person who signed up by
 * themselves, with a personal workspace. Only adds; skips what exists.
 */
class DemoIdentitySeeder extends Seeder
{
    public function run(RuleService $rules, RuleTargets $targets, PersonalWorkspaces $workspaces): void
    {
        $house = Partner::query()->where('is_house', true)->first();
        if ($house === null) {
            return;
        }

        $smsSet = RuleValue::query()
            ->where('rule_key', 'notifications.sms_enabled')
            ->where('scope_type', RuleScope::Partner)
            ->where('scope_id', $house->getKey())
            ->where('status', RuleValueStatus::Active)
            ->exists();
        if (! $smsSet) {
            $rules->set($targets->partner($house), 'notifications.sms_enabled', RuleMode::Set, true, 'Demo data: phone sign-up (log driver, nothing is sent)', trusted: true);
        }

        if (User::query()->where('email', 'self.demo@demo.test')->exists()) {
            return;
        }

        $password = Str::password(16);
        $user = new User;
        $user->forceFill([
            'name' => 'Demo Self-serve',
            'email' => 'self.demo@demo.test',
            'email_verified_at' => now(),
            'phone' => '+8801700000001',
            'phone_verified_at' => now(),
            'password' => $password,
            'locale' => 'bn',
        ])->save();
        $workspaces->create($user, $house, 'BD', 'bn');
        app(CurrentContext::class)->clear();

        $this->command?->info("Demo login: self.demo@demo.test (or phone 01700000001) / {$password}");
    }
}
