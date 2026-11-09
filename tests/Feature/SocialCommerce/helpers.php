<?php

use App\Platform\Meta\Models\MetaApp;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\SocialCommerce\Services\Pages;

/* Helpers shared by the Facebook selling tests (Meta faked: no call leaves the test). */

if (defined('META_SECRET')) {
    return;
}

const META_SECRET = 'abcdef0123456789abcdef0123456789';

/** A Meta app in use for a partner (as if approved). */
function activeMetaApp(Partner $partner, string $appId = '1234567890', string $name = 'Partner A Shop'): MetaApp
{
    $app = new MetaApp;
    $app->forceFill(['partner_id' => $partner->getKey(), 'app_id' => $appId, 'app_secret' => META_SECRET, 'app_name' => $name, 'status' => MetaApp::ACTIVE, 'version' => 1])->save();

    return $app;
}

/**
 * Meta's Graph API, faked: sign-in, the person's pages, photos and posts.
 *
 * @param  array<string, callable(Request): mixed>  $overrides  by path ending
 */
function fakeMeta(array $overrides = []): void
{
    // Faked again in a test, the newest answers win (every registered fake reads the same overrides).
    $GLOBALS['meta_overrides'] = $overrides;
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        foreach ($GLOBALS['meta_overrides'] as $end => $answer) {
            if (str_ends_with($path, $end)) {
                return $answer($request);
            }
        }

        return match (true) {
            str_ends_with($path, '/oauth/access_token') => Http::response(['access_token' => 'PERSON_TOKEN']),
            str_ends_with($path, '/me/accounts') => Http::response(['data' => [
                ['id' => '111', 'name' => 'BBU Shop', 'access_token' => 'PAGE_TOKEN_111', 'tasks' => ['CREATE_CONTENT', 'MODERATE'], 'picture' => ['data' => ['url' => 'https://example.test/p.jpg']]],
                ['id' => '222', 'name' => 'BBU Fans', 'access_token' => 'PAGE_TOKEN_222', 'tasks' => ['ANALYZE']],
            ]]),
            str_ends_with($path, '/me/permissions') => Http::response(['data' => array_map(fn (string $scope) => ['permission' => $scope, 'status' => 'granted'], Pages::SCOPES)]),
            str_ends_with($path, '/me') => Http::response(['id' => 'PERSON1']),
            str_ends_with($path, '/subscribed_apps') => Http::response(['success' => true]),
            str_ends_with($path, '/111/photos') => Http::response(['id' => 'PHOTO_'.str()->random(4)]),
            str_ends_with($path, '/111/feed') => Http::response(['id' => '111_999']),
            default => Http::response(['error' => ['code' => 100, 'message' => 'Unknown']], 400),
        };
    });
}
