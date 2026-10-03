<?php

return [

    'errors' => [
        'module_not_found' => 'This module does not exist. Check the module name.',
        'module_disabled' => ':module is turned off for your organization. Ask an administrator to turn it on.',
        'not_in_plan' => ':module is not included in your plan. Upgrade the plan to use it.',
        'not_offered' => ':module is not offered by your service provider. Contact them if you need it.',
        'sector_not_allowed' => ':module is not available for this business sector.',
        'consent_required' => ':module needs an administrator\'s consent before it can be turned on. Give consent first.',
        'locked_by_parent' => ':module is locked by :organization. Ask them to change it.',
        'core_module' => ':module is part of the core platform and cannot be turned off.',
        'dependents_need_confirmation' => 'Turning off :module also turns off: :modules. Send confirm=true to continue.',
        'consent_not_applicable' => ':module does not need consent.',
        'consent_not_found' => 'There is no active consent to revoke at this level.',
        'purge_confirm_mismatch' => 'To confirm, type the module key exactly: :key',
        'purge_requires_disabled' => 'Turn off :module before deleting its data.',
        'purge_already_pending' => 'A data deletion for this module is already scheduled. Cancel it first to change it.',
        'purge_not_found' => 'There is no scheduled data deletion for this module.',
    ],

    'reasons' => [
        'enabled' => 'On',
        'not_in_plan' => 'Not included in the current plan',
        'not_offered' => 'Not offered by your service provider',
        'sector_not_allowed' => 'Not available for this sector',
        'consent_missing' => 'Waiting for administrator consent',
        'not_enabled' => 'Off (not turned on yet)',
        'disabled' => 'Turned off',
        'locked_disabled' => 'Turned off and locked',
        'dependency_disabled' => 'Needs these modules to be on: :modules',
    ],

    'messages' => [
        'consent_revoked' => 'Consent revoked. The module is now off.',
        'purge_scheduled' => 'Data deletion is scheduled for :date. You can cancel it until then.',
    ],

    // Sidebar sections; a module's menu sits under its category unless it names one.
    // Added to every module's sidebar entry.
    'menu' => [
        'dashboard' => 'Dashboard',
        'settings' => 'Settings',
    ],

    'sections' => [
        'people' => 'People',
        'finance' => 'Finance',
        'business' => 'Business',
        'governance' => 'Governance',
        'ai' => 'AI',
        'platform' => 'Platform',
        'sustainability' => 'Sustainability',
    ],

    'your_provider' => 'your service provider',

];
