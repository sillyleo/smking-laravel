<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /smking-preview?token=&path= — CMS draft preview entry.
 *
 * Sets the short-lived `smking_preview_token` cookie then redirects to the
 * (same-origin, relative) page, where `<x-smking-cms>` picks the cookie up
 * and CmsClient fetches draftBlocks (status "preview", uncached).
 *
 * Fail-safe / install-only:
 *   - No token → plain redirect (live page); no cookie set.
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

        $response = redirect($path);

        if ($token !== '') {
            // 15 min, httpOnly, lax — mirrors the saas token TTL. Secure is
            // derived from the current request (HTTPS) so the bearer token is
            // never sent in clear text on a production HTTPS site, while local
            // plain-HTTP dev still works.
            $response->withCookie(cookie(
                name: 'smking_preview_token',
                value: $token,
                minutes: 15,
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: true,
                raw: false,
                sameSite: 'lax',
            ));
        }

        return $response;
    }
}
