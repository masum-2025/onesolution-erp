<?php

namespace App\Platform\Security\Http;

use Illuminate\Http\Response;

/**
 * robots.txt at the app's addresses (the platform's and partners' domains):
 * careers pages are open to search engines, the app, the API, files and
 * callbacks are not (they also answer "noindex"). A school's own site
 * answers its robots.txt itself, before the app runs. No list of clients
 * here: each careers page links its own sitemap.
 */
class RobotsTxtController
{
    private const CLOSED = ['/api/', '/session/', '/sanctum/', '/files/', '/exports/', '/audit-exports/', '/payments/', '/internal/', '/partner-preview/', '/brand-assets/', '/client-brand-assets/'];

    public function __invoke(): Response
    {
        $lines = ['User-agent: *', 'Allow: /careers/', 'Allow: /s/'];
        foreach (self::CLOSED as $path) {
            $lines[] = "Disallow: {$path}";
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
