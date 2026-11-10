<?php

// Rule labels, key = part after "pharmacy.".
return [
    'near_expiry_days' => ['label' => 'Near expiry (days)', 'description' => 'Medicines expiring within this many days show in red at the counter and in stock.'],
    'return_before_expiry_days' => ['label' => 'Return to supplier before expiry (days)', 'description' => 'Batches expiring within this many days are listed to go back to the supplier.'],
    'block_expired_sale' => ['label' => 'Never sell expired batches', 'description' => 'The counter sells only batches still good; when only expired stock is left the sale is refused.'],
    'block_above_mrp' => ['label' => 'Never sell above MRP', 'description' => 'A medicine\'s selling price cannot be above its printed MRP.'],
    'controlled_requires_prescription' => ['label' => 'Controlled medicines need a prescription', 'description' => 'Selling a controlled medicine needs the patient\'s name and the doctor\'s BMDC number; it goes into the controlled-drug register.'],
    'sell_loose_pieces' => ['label' => 'Sell loose pieces', 'description' => 'Allow selling single pieces of medicines that come in strips.'],
    'number_prefixes' => ['label' => 'Number prefixes', 'description' => 'The letters before the numbers of supplier returns (e.g. SR-2026-000001).'],
    'categories' => [
        'expiry' => 'Expiry',
        'selling' => 'Selling',
        'numbering' => 'Numbering',
    ],
];
