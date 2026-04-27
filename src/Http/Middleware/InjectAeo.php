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
 *
 * Install-verification contract (v0.2.0):
 *   - Every middleware-handled response carries X-Smking-Status + X-Smking-Path
 *     headers, so `curl -I` is enough to verify the SDK is wired up.
 *   - The `data-smking-injected="1"` attribute on <html> is decoupled from
 *     content readiness — it appears whenever the middleware actually ran on
 *     an HTML 200 GET, regardless of backend audit status.
 *   - Local / development environments get a HTML comment explaining why
 *     content wasn't injected; production stays silent.
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

        $path = $this->normalizePath($request);

        // auto_inject=false: emit verification headers but never touch HTML.
        // The middleware still registers in v0.2.0 so doctor + curl -I work
        // even when injection is disabled.
        if (! (bool) $this->config->get('smking.auto_inject', true)) {
            $this->emitHeaders($response, 'disabled', $path);

            return $response;
        }

        $aeo = $this->client->forPath($path, $request->fullUrl());
        $this->emitHeaders($response, $aeo->status, $path);

        // If the response body is already encoded (gzip / br / deflate) we
        // can't rewrite it without decoding first — str_contains / preg_replace
        // would corrupt the binary payload. Emit headers above for install
        // verification, but leave the body untouched. Customers running
        // PHP output_compression or a webserver-level gzip module land here.
        if ((string) $response->headers->get('Content-Encoding', '') !== '') {
            return $response;
        }

        // HEAD responses (and the rare empty GET) carry no body to rewrite —
        // headers are still emitted above so `curl -I` install verification
        // works without a real GET. Frame skipping happens here, not in
        // shouldInject(), so HEAD still gets the AeoResponse status header
        // instead of a hardcoded fallback.
        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        $rewritten = $this->rewriteHtml($content, $aeo, $path, $request->fullUrl());

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
        // Accept GET *and* HEAD — HEAD is GET-without-body in HTTP semantics,
        // and `curl -I` is the canonical install-verification command. Laravel
        // dispatches HEAD to the same controller as GET; here we let it
        // through so the X-Smking-* headers come back to the operator.
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return false;
        }

        // Don't touch HTML downloads. Even when Content-Type is text/html,
        // `Content-Disposition: attachment` means the user is downloading a
        // file (HTML report, exported document) — injecting AEO content
        // there ships unwanted markup into the saved file.
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        if ($disposition !== '' && stripos($disposition, 'attachment') !== false) {
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

    private function emitHeaders(Response $response, string $status, string $path): void
    {
        $response->headers->set('X-Smking-Status', $status);
        $response->headers->set('X-Smking-Path', $path);
    }

    private function rewriteHtml(string $html, AeoResponse $aeo, string $path, string $url): string
    {
        // Idempotency: if upstream already marked, leave entirely alone.
        if (str_contains($html, 'data-smking-injected')) {
            return $html;
        }

        if ($aeo->isReady()) {
            $html = $this->injectReadyFragments($html, $aeo);
        } elseif ($this->isDebugEnabled()) {
            $html = $this->injectDevComment($html, $aeo->status, $path, $url);
        }

        // Mark always — even when no fragments were injected. This is the core
        // decoupling: install verification works without the backend ready.
        $marked = $this->markDocument($html);

        return $marked ?? $html;
    }

    private function injectReadyFragments(string $html, AeoResponse $aeo): string
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

        $html = $this->injectBefore($html, '</head>', implode('', $headFragments));
        $html = $this->injectBefore($html, '</body>', implode('', $bodyFragments));

        return $html;
    }

    private function injectDevComment(string $html, string $status, string $path, string $url): string
    {
        $reason = match ($status) {
            AeoResponse::STATUS_NOT_FOUND => 'backend has not crawled this path yet, or url unreachable from public internet (e.g. .test TLD)',
            AeoResponse::STATUS_PENDING => 'backend is crawling, retry shortly',
            default => 'unknown status',
        };

        $comment = sprintf(
            '<!-- smking: middleware-ran path=%s status=%s url=%s reason=%s -->',
            $this->safeComment($path),
            $this->safeComment($status),
            $this->safeComment($url),
            $this->safeComment($reason),
        );

        return $this->injectBefore($html, '</body>', $comment);
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

    private function markDocument(string $html): ?string
    {
        if (str_contains($html, 'data-smking-injected')) {
            return $html;
        }

        $result = preg_replace(
            '/<html\b([^>]*)>/i',
            '<html$1 data-smking-injected="1">',
            $html,
            1,
        );

        // No <html> tag found — caller falls back to original HTML, headers
        // remain on the response so curl -I still verifies the install.
        if ($result === null || $result === $html) {
            return null;
        }

        return $result;
    }

    private function isDebugEnabled(): bool
    {
        $debug = $this->config->get('smking.debug');
        if ($debug !== null) {
            return (bool) $debug;
        }

        return in_array(app()->environment(), ['local', 'testing', 'development'], true);
    }

    /**
     * Prevent </script> inside the JSON from breaking out of the script tag.
     */
    private function safeScript(string $json): string
    {
        return str_replace('</', '<\/', $json);
    }

    /**
     * Prevent the literal `--` from prematurely closing an HTML comment.
     */
    private function safeComment(string $value): string
    {
        return str_replace('--', '__', $value);
    }
}
