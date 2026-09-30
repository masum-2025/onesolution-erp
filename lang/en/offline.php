<?php

return [

    'errors' => [
        'device_not_found' => 'This device is not registered to your account. Set it up for offline work again.',
        'not_allowed' => 'You are not allowed to work offline here. Ask an administrator for the "Work offline" permission.',
        'wrong_organization' => 'This device was set up for another organization. Switch to it, or set up the device again here.',
        'lease_invalid' => 'This device\'s offline pass is not valid. Go online and refresh it, then sync again.',
        'lease_expired' => 'This device\'s offline pass has expired. Go online and refresh it, then sync again. Your changes are kept on the device.',
        'too_many' => 'Send at most :max changes at once; the rest can follow in the next sync.',
        'organization_inactive' => 'This organization is not active, so nothing can be synced.',
        'quarantine_not_found' => 'This held change does not exist or is not yours to see.',
        'already_decided' => 'Someone already decided on this change.',
    ],

    // Per change, in the sync answer.
    'results' => [
        'stale_version' => 'Someone changed this record since the device last saw it. Look at the current version and apply your change again.',
        'kind_unavailable' => 'This kind of record cannot be changed offline here.',
        'lease_unknown' => 'This change was not made under an offline pass this server gave the device.',
        'made_after_lease' => 'This change was made after the device\'s offline pass ended, so it was not accepted.',
        'data_moving' => 'Your organization\'s data is being moved to a new storage location. This change is kept on your device and will be sent again in a few minutes.',
        'append_only' => 'Payments recorded offline can only be added, never changed or removed. Record a correction instead.',
        'offline_payments_off' => 'Recording payments offline is not allowed here.',
        'rules_changed' => 'An important setting changed since this change was made. Check it and make the change again.',
        'forbidden' => 'You are not allowed to make this change.',
        'read_only' => 'This organization is read-only at the moment, so the change was not applied.',
        'invalid' => 'Some values were not accepted. Correct them and try again.',
        'not_found' => 'The record no longer exists.',
        'device_revoked' => 'This device was removed from offline work. Your change is held for an administrator to decide.',
        'membership_ended' => 'You no longer work here. Your change is held for an administrator to decide.',
        'permission_missing' => 'You may no longer work offline here. Your change is held for an administrator to decide.',
        'module_off' => 'Offline mode was turned off. Your change is held for an administrator to decide.',
    ],

    'messages' => [
        'device_registered' => 'This device is ready to work offline.',
        'device_revoked' => 'Device removed. It clears its offline data on its next connection.',
        'released' => 'The change was applied.',
        'discarded' => 'The change was discarded.',
    ],

];
