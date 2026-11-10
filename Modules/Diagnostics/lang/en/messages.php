<?php

return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its branches: a group keeps no lab records of its own.',
        'version_conflict' => 'Someone changed this a moment ago. It has been loaded again; make your change once more.',
        'no_currency' => 'Set the company currency first (company settings).',
        'bad_link' => 'Choose one from this company (and this branch or one below it).',
        'in_use' => 'Orders already use this. Mark it "not in use" instead of removing it.',
        'test_not_found' => 'This test is not here. Open the page again.',
        'referrer_not_found' => 'This doctor is not here. Choose another or add them under Referring doctors.',
        'patient_not_found' => 'This patient is not here. Search for the patient again.',
        'order_not_found' => 'This order is not here. Open the page again.',
        'order_test_not_found' => 'This test is not on the order any more. Open the order again.',
        'sample_not_found' => 'No sample with this barcode here. Check the label and scan again.',
        'test_unknown' => 'One of the tests is not in use any more. Pick the tests again.',
        'nothing_to_collect' => 'Every sample of this order is already taken.',
        'sample_rejected' => 'This sample was already rejected.',
        'sample_has_results' => 'Results are already entered from this sample, so it cannot be rejected.',
        'not_collected' => 'Take the sample first, then enter results.',
        'not_resulted' => 'There are no results waiting to be verified on this test.',
        'own_results' => 'You entered these results, so someone else must verify them.',
        'not_verified' => 'The report can be given only after every test is verified.',
    ],
    'validation' => [
        'taken' => 'Another test already has this code. Use a different one.',
        'service_code' => 'No service with this code in "Services and prices" (Patient billing). Add it there first, then use its code.',
        'unknown_parameter' => 'A value was sent for something this test does not have. Open the order again.',
        'number' => 'Enter a number, like 12.5.',
        'choice' => 'Pick one of the listed answers.',
    ],
    'timeline' => [
        'report' => 'Lab report :number',
    ],
];
