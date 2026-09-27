<?php

return [
    'link_approval' => [
        'label' => 'Approving portal links',
        'description' => 'Manual: you approve every link. Automatic: a person who joins with the invited email or phone, verified, is linked at once.',
    ],
    'invitation_valid_days' => [
        'label' => 'Invitation valid for (days)',
        'description' => 'How long a portal invitation link and code can be used.',
    ],
    'max_links_per_person' => [
        'label' => 'Records per person',
        'description' => 'How many records (e.g. children) one person may be linked to here.',
    ],
    'hidden_fields' => [
        'label' => 'Fields hidden in the portal',
        'description' => 'Per kind of record, the fields portal members never see.',
    ],
    'allow_online_payment' => [
        'label' => 'Online payment in the portal',
        'description' => 'Whether portal members may pay online (fees, invoices) where a module offers it.',
    ],
];
