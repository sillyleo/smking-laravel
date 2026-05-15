<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * TrackCrawlerHit — AI bot + AI referral 偵測（Laravel SDK side）。
 *
 * Mirrors WP plugin AICB_Crawler_Tracker and Next.js smkingProxy. Runs as
 * terminable middleware so the network call happens AFTER the response has
 * been sent. On PHP-FPM with `fastcgi_finish_request`, customers feel zero
 * latency; on classic CGI, this still completes after the response has
 * been buffered out, so the user-perceived TTFB isn't affected — only the
 * total connection close timing.
 *
 * Failure modes:
 *   - Missing api_key / base_url → silent skip
 *   - Network / timeout / 4xx / 5xx → swallowed (response already sent)
 *
 * Design contract:
 *   - NEVER throws into customer code (terminate() is wrapped in try/catch)
 *   - NEVER touches the response (pure read-only)
 *   - Re-uses InjectAeo's smking.api_key + smking.base_url config; opt-out
 *     via smking.track_crawler_hits = false
 */
class TrackCrawlerHit
{
    /**
     * AI 答案介面的 referer hostname — 跟 packages/smking-next/src/lib/crawlers.ts
     * 的 AI_REFERRER_HOSTS 同步。
     */
    private const AI_REFERRER_HOSTS = [
        'chatgpt.com',
        'chat.openai.com',
        'perplexity.ai',
        'www.perplexity.ai',
        'claude.ai',
        'gemini.google.com',
        'copilot.microsoft.com',
        'bing.com',
        'www.bing.com',
    ];

    /** @var array<int, array{name: string, regex: string, category: string}>|null */
    private static ?array $cachedPatterns = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Pass-through. Real work happens in terminate() so the customer's
     * route handler is never delayed by classification or buffering.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Laravel's terminable hook — called after the response has been sent
     * back to the customer. We classify + POST here so the customer never
     * waits on smking ingestion.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            // Tests / console / opt-out short-circuits.
            if (app()->runningUnitTests() && ! (bool) $this->config->get('smking.inject_in_tests', false)) {
                return;
            }
            if ((bool) $this->config->get('smking.track_crawler_hits', true) === false) {
                return;
            }

            $hit = $this->classify($request);
            if ($hit === null) {
                return;
            }

            $apiKey = (string) $this->config->get('smking.api_key', '');
            $baseUrl = rtrim((string) $this->config->get('smking.base_url', ''), '/');
            if ($apiKey === '' || $baseUrl === '') {
                return;
            }

            $referer = (string) $request->headers->get('referer', '');
            $payload = [
                'hits' => [[
                    'bot'          => $hit['bot'],
                    'bot_category' => $hit['bot_category'],
                    'purpose'      => $hit['purpose'],
                    'path'         => mb_substr('/' . ltrim($request->path(), '/'), 0, 512),
                    'page_url'     => $request->url(),
                    'user_agent'   => (string) $request->userAgent(),
                    'referer'      => $referer !== '' ? $referer : null,
                    'status'       => $response->getStatusCode(),
                    'timestamp'    => gmdate('c'),
                ]],
            ];

            $this->http
                ->withHeaders([
                    'X-Public-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(2)
                ->post($baseUrl . '/api/v1/crawler-hit', $payload);
        } catch (Throwable) {
            // Tracking must never bubble — response is already sent.
            // Silent skip; next request retries.
        }
    }

    /**
     * @return array{bot: string|null, bot_category: string|null, purpose: string}|null
     */
    private function classify(Request $request): ?array
    {
        $ua = (string) $request->userAgent();
        $bot = $this->detectBot($ua);
        if ($bot !== null) {
            $purpose = $bot['category'] === 'training' ? 'training' : 'realtime_citation';

            return [
                'bot' => $bot['name'],
                'bot_category' => $bot['category'],
                'purpose' => $purpose,
            ];
        }

        $referer = (string) $request->headers->get('referer', '');
        if ($this->isAiReferral($referer)) {
            return [
                'bot' => null,
                'bot_category' => null,
                'purpose' => 'ai_referral',
            ];
        }

        return null;
    }

    /**
     * @return array{name: string, category: string}|null
     */
    private function detectBot(string $ua): ?array
    {
        if ($ua === '') {
            return null;
        }

        if (self::$cachedPatterns === null) {
            $loaded = require __DIR__ . '/../../Support/crawler-patterns.php';
            self::$cachedPatterns = is_array($loaded) ? $loaded : [];
        }

        foreach (self::$cachedPatterns as $p) {
            if (! isset($p['regex'], $p['name'], $p['category'])) {
                continue;
            }
            if (preg_match($p['regex'], $ua)) {
                return [
                    'name' => (string) $p['name'],
                    'category' => (string) $p['category'],
                ];
            }
        }

        return null;
    }

    private function isAiReferral(string $referer): bool
    {
        if ($referer === '') {
            return false;
        }
        $host = parse_url($referer, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::AI_REFERRER_HOSTS, true);
    }
}
