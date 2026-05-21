<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Smking\Laravel\AeoClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves /sitemap.xml as a dedicated route (replaces middleware takeover removed in v0.13.0).
 *
 * Why dedicated controller instead of middleware takeover:
 *   - Explicit registration in customer's routes/web.php — visible in
 *     `php artisan route:list`, debuggable like any other controller.
 *   - Customer's own /sitemap.xml route (if they define one in routes/web.php
 *     BEFORE smking's) wins via Laravel route resolution — no need for
 *     middleware to inspect customer 404 response.
 *   - Removes the takeover bypass bug class where auto_inject=false didn't
 *     gate path takeover (audit handoff #3).
 *
 * Implementation is thin — all the hardening (cache + namespace + circuit
 * breaker + adaptive TTL on miss) is already in AeoClient::fetchPublicFile().
 * This controller is just the routing glue.
 */
class SitemapController
{
    public function __construct(
        private readonly AeoClient $client,
    ) {
    }

    public function __invoke(): Response
    {
        $result = $this->client->fetchPublicFile('sitemap');

        // Fail mode: cache miss + fetch fail → 503. Empty sitemap 200 would
        // tell Google "site has no URLs" which actively hurts SEO; 503 means
        // "temporary unavailable, retry later" — Google backs off but does
        // not de-index. AeoClient already applies adaptive backoff to the
        // cache miss to avoid hammering SaaS during an outage.
        if ($result === null) {
            return new Response('', 503, [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Smking-Status' => 'unavailable',
            ]);
        }

        return new Response($result['body'], 200, [
            'Content-Type' => $result['contentType'],
            'Cache-Control' => 'public, max-age=3600',
            'X-Smking-Status' => 'served',
        ]);
    }
}
