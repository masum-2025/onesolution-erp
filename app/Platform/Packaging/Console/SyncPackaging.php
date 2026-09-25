<?php

namespace App\Platform\Packaging\Console;

use App\Platform\Packaging\Models\Plan;
use App\Platform\Packaging\Models\PlanPrice;
use App\Platform\Packaging\Models\SectorPackage;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\SectorCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mirror plans (with prices) and sector packages from the data files into
 * the database. Run on every deploy. Removed entries are deprecated, never
 * deleted, so organizations on an old plan keep working.
 */
class SyncPackaging extends Command
{
    protected $signature = 'packaging:sync';

    protected $description = 'Sync plans, plan prices and sector packages into the database';

    public function handle(PlanCatalog $plans, SectorCatalog $sectors): int
    {
        DB::transaction(function () use ($plans, $sectors) {
            foreach ($plans->all() as $definition) {
                $plan = Plan::query()->updateOrCreate(['key' => $definition->key], [
                    'modules' => $definition->modules,
                    'is_public' => $definition->public,
                    'sort_order' => $definition->sortOrder,
                    'deprecated_at' => null,
                ]);

                $kept = [];
                foreach ($definition->prices as $price) {
                    $kept[] = PlanPrice::query()->updateOrCreate(
                        ['plan_id' => $plan->getKey(), 'currency_code' => $price['currency'], 'period' => $price['period']],
                        ['amount_minor' => $price['amount_minor']],
                    )->getKey();
                }
                PlanPrice::query()->where('plan_id', $plan->getKey())->whereNotIn('id', $kept)->delete();
            }

            Plan::query()->whereNotIn('key', $plans->keys())->whereNull('deprecated_at')->update(['deprecated_at' => now()]);

            foreach ($sectors->all() as $definition) {
                SectorPackage::query()->updateOrCreate(['key' => $definition->key], [
                    'modules' => $definition->modules,
                    'rules' => $definition->rules,
                    'role_templates' => $definition->roleTemplates,
                    'demo_seeder' => $definition->demoSeeder,
                    'version' => $definition->version(),
                    'sort_order' => $definition->sortOrder,
                    'deprecated_at' => null,
                ]);
            }

            SectorPackage::query()->whereNotIn('key', $sectors->keys())->whereNull('deprecated_at')->update(['deprecated_at' => now()]);
        });

        $this->info(count($plans->all()).' plans and '.count($sectors->all()).' sector packages synced.');

        return self::SUCCESS;
    }
}
