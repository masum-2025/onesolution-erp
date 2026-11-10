<?php

// Errors and checks.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its units: a group keeps no records of its own.',
        'version_conflict' => 'Someone changed this a moment ago. It is fresh now; make your change again.',
        'no_currency' => 'Set the company\'s currency first (company settings).',
        'bad_link' => 'Choose one from this company (and this unit or one below it).',
        'in_use' => 'Other records still use this one. Change or remove them first.',
        'generic_not_found' => 'That generic does not exist here. Reload the page.',
        'manufacturer_not_found' => 'That manufacturer does not exist here. Reload the page.',
        'medicine_not_found' => 'That medicine does not exist here. Reload the page.',
        'register_not_found' => 'That counter does not exist here. Choose another counter.',
        'return_not_found' => 'That supplier return does not exist here. Reload the page.',
        'above_mrp' => ':medicine sells above its MRP. Lower its selling price in the medicine list, then sell again.',
        'prescription_needed' => ':medicine is a controlled medicine. Enter the patient\'s name and the doctor\'s BMDC number from the prescription.',
        'expired_stock' => 'Only expired stock of :medicine is left. Do not sell it: return it to the supplier, or sell a substitute.',
        'loose_pieces' => ':medicine is sold by the strip here. Choose strip or box.',
        'no_pack' => ':medicine has no such pack. Set pieces per strip (and strips per box) in the medicine list, or sell by the piece.',
        'controlled_offline' => 'Controlled medicines are not sold offline. Wait for the connection to come back.',
        'own_approval' => 'You made this, so someone else must approve it.',
        'decided' => 'This was decided already. Reload the page.',
        'batch_short' => 'Batch :batch of :medicine does not hold that much here. Check the quantity in Stock and expiry.',
    ],
    'validation' => [
        'taken' => 'Another record already has this. Use a different one.',
        'above_mrp' => 'The selling price cannot be above the MRP.',
        'medicine' => 'Choose a medicine from the list (in use).',
        'pack' => 'This medicine has no such pack: set its pieces per strip and strips per box first.',
        'warehouse' => 'Choose a warehouse of this branch.',
        'range' => 'Choose at most one year.',
    ],
];
