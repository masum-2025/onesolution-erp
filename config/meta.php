<?php

/*
| Meta (Facebook, later Instagram and WhatsApp): how the platform talks to the
| Graph API. Each partner's own app (app id and secret) is runtime data in
| meta_apps, never here; the house partner's app serves direct clients and,
| where the rule social_commerce.house_app_allowed lets it, a partner's clients.
*/

return [
    // Graph API version every call uses (Meta keeps a version two years).
    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
    'dialog_url' => env('META_DIALOG_URL', 'https://www.facebook.com'),
    // Seconds to wait for Meta (uploading photos takes longest).
    'timeout' => (int) env('META_TIMEOUT', 30),
    // A change of a partner's app with no second owner to approve it takes effect after this wait.
    'single_approver_wait_hours' => (int) env('META_SINGLE_APPROVER_WAIT_HOURS', 24),
    // Where Meta sends a person back after signing in (on each of the partner's domains).
    'redirect_path' => '/api/social/facebook/callback',
    // Minutes a page-connect sign-in stays open (state, the pages found).
    'connect_minutes' => 10,
];
