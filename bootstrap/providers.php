<?php

use App\Platform\Modules\ModulesServiceProvider;
use App\Platform\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ModulesServiceProvider::class,
];
