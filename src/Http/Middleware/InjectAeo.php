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

        // Markdown for Agents (RFC-style content negotiation, v0.4.0+).
        // When an autonomous agent / browser-agent / MCP client requests
        // `Accept: text/markdown`, we serve the path's AEO content as a
        // structured markdown document instead of HTML. The forPath() call
        // above already registered the path for background crawling, so a
        // first-time miss here recovers on the next request.
        $flags = (array) $this->config->get('smking.inject', []);
        if (($flags['markdown'] ?? true) && $this->wantsMarkdown($request)) {
            $markdown = $this->client->getMarkdown($path);
            if ($markdown !== null) {
                return $this->respondWithMarkdown($response, $markdown);
            }
            // Backend hasn't crawled this path yet (or md API failed) —
            // fall through to HTML so the agent gets *something* this turn.
        }

        // v0.5.0: advertise the markdown alternate via Link header on every
        // HTML response. Agent clients that don't speculatively send
        // `Accept: text/markdown` can discover the alternate from the
        // header (RFC 8288). Cloudflare's isitagentready.com page-level
        // audit explicitly checks this — sites that emit it score higher.
        //
        // v0.6.1: gate on env presence — if the customer hasn't configured
        // SMKING_API_KEY + SMKING_BASE_URL yet (e.g. installed in prod
        // without env yet), advertising a markdown alternate is a lie:
        // the actual md API call will fail and the agent gets HTML back.
        // Better to stay silent until config is in place.
        if (($flags['markdown'] ?? true) && $this->isConfigured()) {
            $this->addMarkdownAlternateLink($response, $request->fullUrl());
        }

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

        // Only rewrite full HTML documents. HTMX / Turbo / Livewire-style
        // fragment endpoints often respond with raw `<div>...</div>` chunks
        // that are still served as text/html — appending JSON-LD / FAQ /
        // SEO meta to those would corrupt the fragment. Headers are still
        // emitted above so the operator can see the SDK ran without our
        // injection breaking partial responses.
        if (! $this->isFullHtmlDocument($content)) {
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

        // v0.3.0 — always override (was: fill gaps). Customers install smking
        // because they want our generated SEO/AEO content live, so hardcoded
        // Blade fallbacks (`<meta name="description" content="App Name">`) and
        // competitor output should be replaced, not deferred to. We strip any
        // existing tag (attribute-order-insensitive) before injecting ours, so
        // the document only ever ends up with one of each.
        if (($flags['meta_description'] ?? true) && $aeo->metaDescription !== '') {
            $html = $this->stripVoidTag($html, 'meta', 'name', 'description');
            $headFragments[] = '<meta name="description" content="'.e($aeo->metaDescription).'" data-smking="aeo">';
        }

        if ($aeo->seo !== null) {
            if (($flags['seo_title'] ?? true) && $aeo->seo->title !== null) {
                $html = $this->stripTitle($html);
                $headFragments[] = '<title data-smking="seo">'.e($aeo->seo->title).'</title>';
            }

            if (($flags['og_title'] ?? true) && $aeo->seo->ogTitle !== null) {
                $html = $this->stripVoidTag($html, 'meta', 'property', 'og:title');
                $headFragments[] = '<meta property="og:title" content="'.e($aeo->seo->ogTitle).'" data-smking="seo">';
            }

            if (($flags['og_description'] ?? true) && $aeo->seo->ogDescription !== null) {
                $html = $this->stripVoidTag($html, 'meta', 'property', 'og:description');
                $headFragments[] = '<meta property="og:description" content="'.e($aeo->seo->ogDescription).'" data-smking="seo">';
            }

            if (($flags['og_image'] ?? true) && $aeo->seo->ogImageUrl !== null) {
                $html = $this->stripVoidTag($html, 'meta', 'property', 'og:image');
                $headFragments[] = '<meta property="og:image" content="'.e($aeo->seo->ogImageUrl).'" data-smking="seo">';
            }

            if (($flags['canonical'] ?? true) && $aeo->seo->canonicalUrl !== null) {
                $html = $this->stripVoidTag($html, 'link', 'rel', 'canonical');
                $headFragments[] = '<link rel="canonical" href="'.e($aeo->seo->canonicalUrl).'" data-smking="seo">';
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
        $html = $this->injectBefore($html, '</body>', $this->wrapByVisibility(implode('', $bodyFragments)));

        return $html;
    }

    /**
     * Wrap body fragments according to `inject.visibility` (v0.6.0+).
     *
     * Why default `sr_only`: prior versions (≤ v0.5.x) injected raw HTML
     * fragments before `</body>`, which meant the markup landed *outside*
     * the SPA's mount target (`#app`) on Vue / React layouts and rendered
     * as visible unstyled text — both as a FOUC during initial paint and
     * as permanent DOM that the framework never claimed. sr_only with an
     * inline-style wrapper hides the fragments visually while keeping the
     * microdata in the DOM as a backup signal for Googlebot. JSON-LD in
     * `<head>` remains the primary AEO signal regardless.
     *
     *   sr_only  — inline-style wrapper (W3C visually-hidden pattern).
     *              Default. SPA-safe, Googlebot reads microdata, AI bots
     *              read JSON-LD from <head>.
     *   visible  — raw fragments (v0.5 behavior). For SSR-only article
     *              sites that genuinely want a no-JS user fallback.
     *   noscript — wraps in <noscript>. Provided but not recommended:
     *              GPTBot skips noscript trees, PerplexityBot is
     *              inconsistent. Visible only when JS is disabled.
     */
    private function wrapByVisibility(string $fragments): string
    {
        if ($fragments === '') {
            return '';
        }

        $mode = (string) $this->config->get('smking.inject.visibility', 'sr_only');

        return match ($mode) {
            'visible' => $fragments,
            'noscript' => '<noscript data-smking="aeo">'.$fragments.'</noscript>',
            // sr_only and any unknown value fall through to the safe default.
            default => '<div data-smking="aeo" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">'.$fragments.'</div>',
        };
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
     * Heuristic: is `$html` a full HTML document (vs an HTMX / Turbo /
     * fragment chunk that just happens to be served as text/html)?
     *
     * Requires both an opening `<html` tag and at least one closing
     * structural tag (`</head>` or `</body>`). Fragment endpoints typically
     * have neither. Operators sending real HTML pages from Blade always
     * have all three.
     */
    private function isFullHtmlDocument(string $html): bool
    {
        if (stripos($html, '<html') === false) {
            return false;
        }

        return stripos($html, '</head>') !== false
            || stripos($html, '</body>') !== false;
    }

    /**
     * Strip <meta>/<link> (void) elements whose `$attrName` equals
     * `$attrValue`, attribute-order-insensitive. Used before injecting
     * smking's version of meta description / og:* / canonical, so the
     * document only ever has one such tag — ours.
     *
     * v0.3.0: replaces v0.2.x's tagHasAttribute() detection. The previous
     * "fill gaps" behavior skipped injection when the host page already
     * wrote the tag; now we strip the host tag and inject ours.
     */
    private function stripVoidTag(
        string $html,
        string $tagName,
        string $attrName,
        string $attrValue,
    ): string {
        $tagPattern = '/<'.preg_quote($tagName, '/').'\b[^>]*>/i';
        $attrPattern = '/\b'
            .preg_quote($attrName, '/')
            .'\s*=\s*["\']'
            .preg_quote($attrValue, '/')
            .'["\']/i';

        $result = preg_replace_callback(
            $tagPattern,
            static fn (array $match): string => preg_match($attrPattern, $match[0]) === 1 ? '' : $match[0],
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Strip the first <title>...</title> from the document. Title has no
     * identifying attribute (one per document by spec), so we remove the
     * existing one before injecting smking's. Limit 1 to avoid eating
     * stray <title> inside <noscript> / <template> on malformed pages.
     */
    private function stripTitle(string $html): string
    {
        $result = preg_replace('/<title\b[^>]*>.*?<\/title>/is', '', $html, 1);

        return $result ?? $html;
    }

    /**
     * Quality-aware Accept-header check for `text/markdown`. Returns true
     * only when the client *explicitly* asked for markdown AND its q-value
     * is at least as high as text/html / *\/* (so a browser sending
     * `Accept: text/html, *\/*;q=0.9` doesn't accidentally trigger md).
     *
     * Variants we want to match:
     *   - text/markdown
     *   - text/markdown, text/html
     *   - text/markdown;q=0.9, text/html;q=0.8
     * Variants we want to reject (HTML preferred):
     *   - text/html, text/markdown            (first-listed at same q wins → HTML)
     *   - text/html;q=0.9, text/markdown;q=0.8
     *   - *\/*                                (browser default)
     *   - (empty / missing Accept)
     */
    private function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->header('Accept', '');
        if ($accept === '' || stripos($accept, 'text/markdown') === false) {
            return false;
        }

        $mdQ = 0.0;
        $htmlQ = 0.0;
        $mdIndex = PHP_INT_MAX;
        $htmlIndex = PHP_INT_MAX;
        $index = 0;

        foreach (explode(',', $accept) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $mediaType = strtolower(trim($segments[0]));
            $q = 1.0;

            $segCount = count($segments);
            for ($i = 1; $i < $segCount; $i++) {
                $param = trim($segments[$i]);
                if (stripos($param, 'q=') === 0) {
                    $q = (float) substr($param, 2);
                }
            }

            if ($mediaType === 'text/markdown') {
                if ($q > $mdQ) {
                    $mdQ = $q;
                    $mdIndex = $index;
                } elseif ($q === $mdQ && $index < $mdIndex) {
                    $mdIndex = $index;
                }
            } elseif (
                $mediaType === 'text/html'
                || $mediaType === '*/*'
                || $mediaType === 'text/*'
            ) {
                if ($q > $htmlQ) {
                    $htmlQ = $q;
                    $htmlIndex = $index;
                } elseif ($q === $htmlQ && $index < $htmlIndex) {
                    $htmlIndex = $index;
                }
            }

            $index++;
        }

        if ($mdQ <= 0.0) {
            return false;
        }
        if ($mdQ > $htmlQ) {
            return true;
        }
        // Same q — break tie by listing order (RFC 7231 doesn't mandate this
        // but it matches what most agent clients expect: list the format
        // you actually want first).
        return $mdQ === $htmlQ && $mdIndex < $htmlIndex;
    }

    /**
     * Replace the response body with the markdown rendition. Adjusts the
     * Content-Type, refreshes Content-Length, and adds `Accept` to Vary so
     * shared caches don't serve markdown to a browser (or HTML to an agent).
     */
    private function respondWithMarkdown(Response $response, string $markdown): Response
    {
        $response->setContent($markdown);
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->remove('Content-Encoding');

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($markdown));
        }

        $existingVary = (string) $response->headers->get('Vary', '');
        $response->headers->set('Vary', $this->mergeVary($existingVary, 'Accept'));

        return $response;
    }

    /**
     * True when both api_key and base_url are non-empty strings — i.e. the
     * SDK is actually capable of serving content. Used to gate the
     * markdown alternate Link header so we don't advertise a capability
     * the unconfigured prod env can't deliver.
     */
    private function isConfigured(): bool
    {
        $apiKey = $this->config->get('smking.api_key');
        $baseUrl = $this->config->get('smking.base_url');

        return is_string($apiKey) && $apiKey !== ''
            && is_string($baseUrl) && $baseUrl !== '';
    }

    /**
     * Emit `Link: <{url}>; rel="alternate"; type="text/markdown"` so agent
     * clients can discover the markdown rendition without sending Accept
     * speculatively. Appends to any existing Link header rather than
     * replacing — customers may already advertise other alternates
     * (rel="next", rel="prev", canonical headers, etc.).
     *
     * Idempotent: if any existing Link entry already advertises a markdown
     * alternate (customer or a previous middleware run), we don't add
     * another. Matches what auditUrl detects in v0.x:
     *   /rel="?alternate"?/i  &&  /type="?text\/markdown"?/i
     */
    private function addMarkdownAlternateLink(Response $response, string $url): void
    {
        $existing = $response->headers->all('Link');
        foreach ($existing as $header) {
            if (
                stripos($header, 'rel="alternate"') !== false
                && stripos($header, 'text/markdown') !== false
            ) {
                return;
            }
        }

        $entry = sprintf('<%s>; rel="alternate"; type="text/markdown"', $url);
        $response->headers->set('Link', $entry, false);
    }

    private function mergeVary(string $existing, string $value): string
    {
        if ($existing === '') {
            return $value;
        }

        $tokens = array_map(
            static fn (string $t): string => trim($t),
            explode(',', $existing),
        );
        foreach ($tokens as $token) {
            if (strcasecmp($token, $value) === 0) {
                return $existing;
            }
        }

        return $existing.', '.$value;
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
