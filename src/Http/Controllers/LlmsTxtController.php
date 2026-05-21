<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Smking\Laravel\AeoClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves /llms.txt as a dedicated route (replaces middleware takeover removed in v0.13.0).
 *
 * See SitemapController docblock for rationale on dedicated controller vs
 * middleware takeover. AeoClient::fetchPublicFile('llms_txt') handles all
 * cache + fallback semantics.
 */
class LlmsTxtController
{
    public function __construct(
        private readonly AeoClient $client,
    ) {
    }

    public function __invoke(): Response
    {
        $result = $this->client->fetchPublicFile('llms_txt');

        // 503 on fail rather than empty body — empty llms.txt would be a
        // wrong signal to AI crawlers ("site explicitly has no LLM-readable
        // index"). 503 means "retry later". AeoClient already backs off.
        if ($result === null) {
            return new Response('', 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
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
