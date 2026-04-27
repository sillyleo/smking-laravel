<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Data\AeoResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites HTML responses to inject AEO content (JSON-LD, meta description,
 * FAQ + summary HTML) fetched from the smking Public AEO API.
 *
 * AI crawlers (ChatGPT, Perplexity, Google AI) don't execute JavaScript, so
 * structured data must be server-rendered. Hence the middleware approach.
 */
class InjectAeo
{
    public function __construct(
        private readonly AeoClient $client,
        private readonly ConfigRepository $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        $aeo = $this->client->forPath(
            $this->normalizePath($request),
            $request->fullUrl(),
        );

        if (! $aeo->isReady()) {
            return $response;
        }

        $rewritten = $this->inject($content, $aeo);
        if ($rewritten === $content) {
            return $response;
        }

        $response->setContent($rewritten);

        // Content-Length must be refreshed or downstream proxies may truncate.
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($rewritten));
        }

        return $response;
    }

    private function shouldInject(Request $request, Response $response): bool
    {
        if (! (bool) $this->config->get('smking.auto_inject', true)) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return false;
        }

        /** @var list<string> $only */
        $only = (array) $this->config->get('smking.only', []);
        /** @var list<string> $except */
        $except = (array) $this->config->get('smking.except', []);

        if ($except && $request->is(...$except)) {
            return false;
        }

        if ($only && ! $request->is(...$only)) {
            return false;
        }

        return true;
    }

    private function normalizePath(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function inject(string $html, AeoResponse $aeo): string
    {
        /** @var array<string, bool> $flags */
        $flags = (array) $this->config->get('smking.inject', []);

        $headFragments = [];

        if (($flags['json_ld'] ?? true) && $aeo->jsonLd !== null) {
            $json = json_encode($aeo->jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $headFragments[] = '<script type="application/ld+json" data-smking="aeo">'.$this->safeScript($json).'</script>';
            }
        }

        if (($flags['meta_description'] ?? true) && $aeo->metaDescription !== '') {
            if (! preg_match('/<meta\s+name=["\']description["\']/i', $html)) {
                $headFragments[] = '<meta name="description" content="'.e($aeo->metaDescription).'" data-smking="aeo">';
            }
        }

        // SEO meta. Each tag has independent conflict detection — we only
        // write a tag the host application hasn't written itself, so client
        // sites that already use Yoast-equivalent solutions (or write meta in
        // their own Blade layout) keep their tags as the source of truth.
        // Mirrors WP Plugin §4 filter coexistence + Next.js mergeMetadata
        // strategy; the philosophy is "fill gaps, never override".
        if ($aeo->seo !== null) {
            if (($flags['seo_title'] ?? true) && $aeo->seo->title !== null) {
                if (! preg_match('/<title\b[^>]*>/i', $html)) {
                    $headFragments[] = '<title data-smking="seo">'.e($aeo->seo->title).'</title>';
                }
            }

            if (($flags['og_title'] ?? true) && $aeo->seo->ogTitle !== null) {
                if (! preg_match('/<meta\s+property=["\']og:title["\']/i', $html)) {
                    $headFragments[] = '<meta property="og:title" content="'.e($aeo->seo->ogTitle).'" data-smking="seo">';
                }
            }

            if (($flags['og_description'] ?? true) && $aeo->seo->ogDescription !== null) {
                if (! preg_match('/<meta\s+property=["\']og:description["\']/i', $html)) {
                    $headFragments[] = '<meta property="og:description" content="'.e($aeo->seo->ogDescription).'" data-smking="seo">';
                }
            }

            if (($flags['og_image'] ?? true) && $aeo->seo->ogImageUrl !== null) {
                if (! preg_match('/<meta\s+property=["\']og:image["\']/i', $html)) {
                    $headFragments[] = '<meta property="og:image" content="'.e($aeo->seo->ogImageUrl).'" data-smking="seo">';
                }
            }

            if (($flags['canonical'] ?? true) && $aeo->seo->canonicalUrl !== null) {
                if (! preg_match('/<link\s+rel=["\']canonical["\']/i', $html)) {
                    $headFragments[] = '<link rel="canonical" href="'.e($aeo->seo->canonicalUrl).'" data-smking="seo">';
                }
            }
        }

        $bodyFragments = [];

        if (($flags['summary_html'] ?? true) && $aeo->summaryHtml !== '') {
            $bodyFragments[] = $aeo->summaryHtml;
        }

        if (($flags['faq_html'] ?? true) && $aeo->faqHtml !== '') {
            $bodyFragments[] = $aeo->faqHtml;
        }

        if ($headFragments === [] && $bodyFragments === []) {
            return $html;
        }

        // Mark the document so the middleware is idempotent — safe across
        // redirects, ESI includes, or cached content.
        if (str_contains($html, 'data-smking-injected')) {
            return $html;
        }

        $html = $this->injectBefore(
            $html,
            '</head>',
            implode('', $headFragments),
        );

        $html = $this->injectBefore(
            $html,
            '</body>',
            implode('', $bodyFragments),
        );

        return $this->markInjected($html);
    }

    private function injectBefore(string $html, string $needle, string $fragment): string
    {
        if ($fragment === '') {
            return $html;
        }

        $position = stripos($html, $needle);
        if ($position === false) {
            return $html.$fragment;
        }

        return substr($html, 0, $position).$fragment.substr($html, $position);
    }

    private function markInjected(string $html): string
    {
        return preg_replace(
            '/<html\b([^>]*)>/i',
            '<html$1 data-smking-injected="1">',
            $html,
            1,
        ) ?? $html;
    }

    /**
     * Prevent </script> inside the JSON from breaking out of the script tag.
     * The spec only requires escaping the literal sequence.
     */
    private function safeScript(string $json): string
    {
        return str_replace('</', '<\/', $json);
    }
}
