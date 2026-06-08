<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /smking-preview?token=&path= — CMS draft preview entry.
 *
 * Redirects to the (same-origin, relative) page carrying the short-lived
 * token as a `?smking_preview=` query param. `<x-smking-cms>` then reads it
 * (CmsClient::previewToken → request()->query('smking_preview')) and fetches
 * draftBlocks (status "preview", uncached).
 *
 * Why a query param and not a cookie: a plaintext cookie set by this bare
 * route gets stripped by the customer's `EncryptCookies` middleware on the
 * inbound CMS-page request (it looks "tampered"), which silently fell back to
 * the published render — and required a fragile global `EncryptCookies::except`
 * exemption in the service provider to work at all. A query param sidesteps
 * cookie encryption entirely (zero customer config) and makes the preview URL
 * visibly distinct from the live page, so authors can tell draft from published.
 *
 * Fail-safe / install-only:
 *   - No token → plain redirect to the live page (no preview param appended).
 *   - Only same-origin relative paths are honored (open-redirect guard):
 *     anything not starting with a single "/" redirects to "/".
 */
final class PreviewController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $rawPath = (string) $request->query('path', '/');
        $path = (str_starts_with($rawPath, '/') && ! str_starts_with($rawPath, '//'))
            ? $rawPath
            : '/';

        if ($token !== '') {
            // Append (don't clobber) any existing query string on the path.
            $separator = str_contains($path, '?') ? '&' : '?';
            $path .= $separator.'smking_preview='.urlencode($token);
        }

        return redirect($path);
    }
}
