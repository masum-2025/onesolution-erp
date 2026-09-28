<?php

return [

    'errors' => [
        'unknown_host' => 'This web address is not connected to any account. Check the address, or ask your service provider for the right one.',
        'role_not_allowed' => 'Your role in this partner account cannot do this. Ask a partner owner.',
        'contrast_text' => 'The :field is too light or too dark for readable text on it. Choose a stronger color.',
        'contrast_surface' => 'The :field is too light to see on a white page (links, borders, buttons). Choose a darker color.',
        'powered_by_locked' => 'Your agreement does not allow hiding "Powered by". Contact the platform team to change it.',
        'host_taken' => 'This address is already connected to an account. Choose another address.',
        'host_reserved' => 'This is an address of the platform itself. Use an address of your own.',
        'domain_not_found' => 'Domain not found. It may have been removed.',
        'verification_failed' => 'The DNS record was not found yet. Add a TXT record named ":name" with the value ":value", wait a few minutes, then check again.',
        'client_not_found' => 'Client not found. Choose a client account from your list.',
        'not_top_level' => 'Choose the client account itself, not a unit inside it.',
        'group_not_active' => 'This group is suspended or closed, so it cannot take a new company. Reactivate it first.',
        'client_limit_reached' => 'Your agreement allows :max client accounts, and all are in use. Contact the platform team to raise it.',
        'country_not_allowed' => 'Your agreement does not cover clients in :country. Contact the platform team.',
        'module_not_offered' => 'Your agreement does not include :module. Contact the platform team to offer it.',
    ],

    'validation' => [
        'image_type' => 'Upload a PNG, WebP or JPEG image. SVG is not accepted.',
        'image_size' => 'The image must be :max KB or smaller.',
        'image_dimensions' => 'The image must be between 16 and 2048 pixels wide and high.',
        'host' => 'Enter a full web address such as erp.example.com, without http:// or a path.',
    ],

    'fields' => [
        'primary_color' => 'main color',
        'secondary_color' => 'second color',
    ],

    'messages' => [
        'brand_saved' => 'Brand saved. Your clients see it right away.',
        'image_saved' => 'Image saved.',
        'image_removed' => 'Image removed.',
        'domain_added' => 'Domain added. Publish the DNS record, then check it.',
        'domain_verified' => 'Domain verified. It is now active.',
        'domain_removed' => 'Domain removed. It no longer opens your app.',
        'module_saved' => 'Module setting saved for all your clients.',
        'client_created' => ':name was created with its owner and sector package.',
        'company_added' => ':name was added to :group, with its sector package. The group\'s owners were told.',
        'client_suspended' => 'Client suspended. Nobody in it can sign in until you reactivate it.',
        'client_reactivated' => 'Client reactivated.',
        'limits_saved' => 'Limits saved for this client.',
    ],

];
