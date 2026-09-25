<?php

/*
|--------------------------------------------------------------------------
| Plan catalog (interim, until Phase 5)
|--------------------------------------------------------------------------
|
| Phase 5 replaces this with the `plans` table (prices, limits, default
| rules). Until then an organization's plan is `organizations.plan_key`,
| inherited down the tree, falling back to tenancy.defaults.plan_key.
| Module manifests list the plans they are sold in (`plans`, '*' = all).
|
*/

return [

    'catalog' => ['starter', 'business', 'enterprise'],

];
