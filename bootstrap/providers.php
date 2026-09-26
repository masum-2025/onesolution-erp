<?php

use App\Platform\Access\AccessServiceProvider;
use App\Platform\Modules\ModulesServiceProvider;
use App\Platform\Packaging\PackagingServiceProvider;
use App\Platform\Partners\PartnersServiceProvider;
use App\Platform\Rules\RulesServiceProvider;
use App\Platform\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    PartnersServiceProvider::class,
    PackagingServiceProvider::class,
    ModulesServiceProvider::class,
    RulesServiceProvider::class,
    AccessServiceProvider::class,
];
