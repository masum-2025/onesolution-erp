<?php

use App\Platform\Access\AccessServiceProvider;
use App\Platform\Audit\AuditServiceProvider;
use App\Platform\Billing\BillingServiceProvider;
use App\Platform\Countries\CountriesServiceProvider;
use App\Platform\DataExport\DataExportServiceProvider;
use App\Platform\Identity\IdentityServiceProvider;
use App\Platform\Modules\ModulesServiceProvider;
use App\Platform\Monitoring\MonitoringServiceProvider;
use App\Platform\Notifications\NotificationsServiceProvider;
use App\Platform\Offline\OfflineServiceProvider;
use App\Platform\Packaging\PackagingServiceProvider;
use App\Platform\PartnerApi\PartnerApiServiceProvider;
use App\Platform\Partners\PartnersServiceProvider;
use App\Platform\Payments\PaymentsServiceProvider;
use App\Platform\Portal\PortalServiceProvider;
use App\Platform\Rules\RulesServiceProvider;
use App\Platform\Security\SecurityServiceProvider;
use App\Platform\SupportAccess\SupportAccessServiceProvider;
use App\Platform\Tenancy\TenancyServiceProvider;
use App\Platform\Transfers\TransfersServiceProvider;
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
    PaymentsServiceProvider::class,
    NotificationsServiceProvider::class,
    TransfersServiceProvider::class,
    PartnerApiServiceProvider::class,
    IdentityServiceProvider::class,
    PortalServiceProvider::class,
    CountriesServiceProvider::class,
    OfflineServiceProvider::class,
    SecurityServiceProvider::class,
    AuditServiceProvider::class,
    MonitoringServiceProvider::class,
];
