<?php

use App\Platform\Access\AccessServiceProvider;
use App\Platform\Billing\BillingServiceProvider;
use App\Platform\DataExport\DataExportServiceProvider;
use App\Platform\SupportAccess\SupportAccessServiceProvider;
use App\Platform\Transfers\TransfersServiceProvider;
use App\Platform\Modules\ModulesServiceProvider;
use App\Platform\Notifications\NotificationsServiceProvider;
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
    SupportAccessServiceProvider::class,
    DataExportServiceProvider::class,
    BillingServiceProvider::class,
    NotificationsServiceProvider::class,
    TransfersServiceProvider::class,
];
