<?php

return [

    'errors' => [
        'no_context' => 'No organization is selected. Choose an organization to continue.',
        'not_member' => 'You are not an active member of this organization. Ask its administrator for access.',
        'organization_inactive' => 'This organization is suspended or archived. Contact your administrator.',
        'partner_inactive' => 'This account is currently suspended. Contact your service provider.',
        'not_partner_member' => 'You do not have access to this partner console.',
        'write_forbidden' => 'You can view this data but cannot change it from your current organization. Switch to the organization that owns it.',
        'organization_not_found' => 'Organization not found. Check the link or choose an organization from your list.',
        'organization_change_forbidden' => 'The organization of a record cannot be changed. Use "Move organization" to change where an organization sits.',
        'invalid_parent' => 'A :type cannot be placed under a :parent. Choose a different parent.',
        'move_cycle' => 'An organization cannot be moved under itself or one of its own units. Choose a different parent.',
        'move_cross_partner' => 'Organizations cannot be moved to another partner here. Ask the platform team to transfer the client.',
        'max_depth' => 'The organization tree cannot be deeper than :max levels. Choose a higher-level parent.',
        'move_same_parent' => 'The organization is already under this parent.',
        'already_member' => 'This person is already a member of the organization.',
        'own_membership' => 'You cannot change your own membership. Ask another owner to do it.',
        'own_context_status' => 'You cannot suspend the organization you are working in. Switch to a higher level first.',
        'credentials' => 'The email or password is incorrect. Check them and try again.',
        'credentials_phone' => 'The phone number or password is incorrect. Check them and try again.',
        'user_not_found' => 'No account uses this email. Ask the person to sign up first, then add them.',
        'user_not_found_invite' => 'No account uses this email yet. Add the owner\'s name and they will be invited by email to set a password.',
        'forbidden' => 'You do not have permission to do this. Ask an owner of the organization.',
        'wrong_address' => 'This account cannot be opened at this web address. Use the address your service provider gave you.',
        'no_support_access' => 'You have no approved support access to this client. Request access from the partner console.',
        'support_ended' => 'This support access has ended. Request new access if you still need it.',
        'two_factor_required' => 'This account requires two-step sign-in. Set it up in My account → Security, then open it again.',
        'read_only_support' => 'Support access is read-only. Ask the client to make this change.',
        'read_only_partner_suspended' => 'Your service provider\'s account is suspended, so this account is read-only for now. You can still view and export your data.',
        'portal_only' => 'This part is for the organization\'s staff. Your portal shows everything you can see here.',
        'read_only_payment_overdue' => 'This workspace is read-only because a bill is overdue. Pay it on the Billing page, or move to the free plan, and everything works again at once. Your data is safe.',
        'export_only' => 'Your service provider\'s account is suspended and the grace period has ended. You can still export all your data.',
    ],

    'validation' => [
        'unknown_field' => 'The field ":field" is not allowed. Remove it and try again.',
        'use_move' => 'The field ":field" cannot be changed here. Use "Move organization" instead.',
    ],

    'types' => [
        'root' => 'top level',
        'group' => 'group',
        'company' => 'company',
        'branch' => 'branch',
        'department' => 'department',
        'personal' => 'personal workspace',
    ],

    'messages' => [
        'logged_out' => 'You have been logged out.',
        'context_entered' => 'Organization selected.',
        'javascript_required' => 'This app needs JavaScript. Turn it on in your browser settings, then reload the page.',
    ],

];
