<?php

return [
    'workspace_name' => ':name\'s workspace',

    'kinds' => [
        'email' => 'email address',
        'phone' => 'phone number',
    ],

    'otp' => [
        'purposes' => [
            'signup' => 'to create your account',
            'recovery' => 'to reset your password',
            'verify_contact' => 'to confirm this address',
        ],
        'sms' => ':code is your :product code :purpose. It expires in :minutes minutes. Never share it with anyone.',
        'mail_subject' => ':code is your :product code',
        'mail_body' => "Your code :purpose is: :code\n\nIt expires in :minutes minutes. Never share it with anyone: :product staff will never ask for it.\n\nIf you did not ask for this code, you can ignore this email.",
    ],

    'errors' => [
        'signup_closed' => 'You cannot create an account here. Ask the organization that uses this service to add you.',
        'bot_check_failed' => 'We could not confirm that you are a person. Complete the check and try again.',
        'disposable_email' => 'Please use an email address you will keep. Temporary inboxes are not accepted.',
        'bad_phone' => 'This does not look like a mobile number. Check the country and the number.',
        'phone_country_not_allowed' => 'Numbers from this country cannot get codes by SMS. Use your email instead.',
        'sms_unavailable' => 'Codes by SMS are not available here. Use your email instead.',
        'too_many_codes' => 'Too many codes were asked for. Try again in :minutes minutes.',
        'resend_too_soon' => 'Please wait :seconds seconds before asking for a new code.',
        'code_wrong' => 'That code is not right. You can try :left more time(s).',
        'code_expired' => 'This code no longer works. Start again to get a new one.',
        'address_taken' => 'This address already has an account. Sign in instead, or reset your password.',
        'cooldown' => 'Your password was reset recently, so your email and phone are locked until :until. This protects your account.',
        'wrong_password' => 'Your current password is not right.',
        'last_sign_in_method' => 'Add an email before removing your phone: you need one way to sign in.',
        'session_not_found' => 'That device is already signed out.',
        'no_personal_workspace' => 'You have no personal workspace.',
    ],

    'messages' => [
        'code_sent' => 'We sent a code to :to.',
        'signed_up' => 'Welcome! Your account is ready.',
        'password_reset' => 'Your password was changed and you are signed in. Every other device was signed out.',
        'profile_saved' => 'Saved.',
        'password_changed' => 'Password changed. Every other device was signed out.',
        'contact_changed' => 'Saved. We now use your new :kind.',
        'phone_removed' => 'Phone number removed.',
        'session_ended' => 'That device was signed out.',
        'sessions_ended' => 'Every other device was signed out.',
        'onboarded' => 'All set.',
    ],

    'validation' => [
        'accept_terms' => 'Please accept the terms to create your account.',
    ],
];
