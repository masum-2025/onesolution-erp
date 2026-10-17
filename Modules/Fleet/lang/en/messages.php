<?php

// Errors and checks.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its units: a group keeps no records of its own.',
        'version_conflict' => 'Someone changed this a moment ago. It is fresh now; make your change again.',
        'no_currency' => 'Set the company\'s currency first (company settings).',
        'bad_link' => 'Choose one from this company (and this unit or one below it).',
        'in_use' => 'Other records still use this one. Change or remove them first.',
        'vehicle_not_found' => 'That Vehicle does not exist here. Reload the page.',
        'trip_not_found' => 'That Trip does not exist here. Reload the page.',
    ],
    'validation' => [
        'taken' => 'Another record already has this. Use a different one.',
    ],
];
