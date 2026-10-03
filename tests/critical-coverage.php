<?php

/*
|--------------------------------------------------------------------------
| Critical-path coverage (Phase 11)
|--------------------------------------------------------------------------
|
| Checked by scripts/coverage-gate.php against a Clover report (CI job
| "coverage"). Areas need at least the given share of their statements run
| by tests; the decision classes below need every statement run.
|
| Module code is measured too (phpunit.xml <source>, CI pcov.directory=.).
| Payroll has no code yet: when the module gets business logic, add its
| folder here and to phpunit.xml (and its calculation classes to "full").
|
*/

return [

    'areas' => [
        // auth
        'app/Platform/Identity' => 90,
        // tenancy (incl. tenant databases)
        'app/Platform/Tenancy' => 90,
        'app/Platform/Rules' => 90,
        'app/Platform/Access' => 90,
        // offline sync
        'app/Platform/Offline' => 90,
        // money
        'app/Platform/Billing' => 90,
        'app/Platform/Payments' => 90,
        // the books (ACC-1)
        'Modules/Accounting/app' => 90,
    ],

    // Classes that decide who may see or change what, and how much money moves.
    'full' => [
        'app/Platform/Tenancy/Scopes/OrganizationScope.php',
        'app/Platform/Tenancy/Concerns/BelongsToOrganization.php',
        'app/Platform/Tenancy/Context/ContextResolver.php',
        'app/Platform/Tenancy/Context/CurrentContext.php',
        'app/Platform/Tenancy/Http/Middleware/ResolveOrganization.php',
        'app/Platform/Tenancy/Http/Middleware/ResolvePartner.php',
        'app/Platform/Tenancy/Policies/OrganizationPolicy.php',
        'app/Platform/Tenancy/Databases/TenantDatabases.php',
        'app/Platform/Tenancy/Databases/UsesTenantDatabase.php',
        'app/Platform/Tenancy/Actions/AttemptLogin.php',
        'app/Platform/Access/AccessResolver.php',
        'app/Platform/Rules/RuleResolver.php',
        'app/Platform/Rules/RuleValueValidator.php',
        'app/Platform/Identity/Services/TwoFactorLogin.php',
        'app/Platform/Identity/Services/StepUp.php',
        'app/Platform/Identity/Http/Middleware/RequireRecentTwoFactor.php',
        'app/Platform/Offline/Services/SyncService.php',
        'app/Platform/Offline/Services/OperationApplier.php',
        'app/Platform/Offline/Services/LeaseSigner.php',
        'app/Platform/Billing/Money.php',
        'app/Platform/Payments/DecimalAmount.php',
        'app/Platform/Payments/Services/PaymentConfirmer.php',
        'Modules/Accounting/app/Services/Books.php',
        'Modules/Accounting/app/Services/Posting.php',
    ],

];
