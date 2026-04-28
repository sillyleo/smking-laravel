<?php

declare(strict_types=1);

namespace Smking\Laravel;

/**
 * Public defaults that customers can spread into their config to opt into
 * the package's recommended baseline. Kept in a separate class (not just
 * inline in `config/smking.php`) so a customer who published config from
 * v0.6 can pull in v0.7's expanded patterns by referencing
 * `Defaults::EXCEPT_PATTERNS` without re-publishing — typical use:
 *
 *   // config/smking.php
 *   'except' => [
 *       ...\Smking\Laravel\Defaults::EXCEPT_PATTERNS,
 *       'my/custom/path',
 *   ],
 *
 * @api this class is part of the public surface — renaming constants is
 *      a breaking change.
 */
final class Defaults
{
    /**
     * Path patterns that should never be sent through the AEO middleware.
     * Used as the default value of `config('smking.except')`.
     *
     * Categories:
     *   - API / data routes (no HTML body to inject)
     *   - Realtime / SPA component endpoints
     *   - Health / liveness probes
     *   - Dev tooling (debug bars, profilers)
     *   - Admin dashboards
     *   - **e-commerce / auth (v0.7.0)** — cart, checkout, account pages
     *     and credentialed flows, where AEO content has no SEO value and
     *     potentially leaks user-specific paths into the audit queue.
     *
     * @var list<string>
     */
    public const EXCEPT_PATTERNS = [
        // API + data routes
        'api/*',
        'v1/*',
        'v2/*',
        'graphql',
        'webhooks/*',
        'oauth/*',

        // Realtime / SPA components — Livewire diff payloads aren't HTML
        // documents and would be corrupted by injection.
        'livewire/*',

        // Health / liveness probes
        'up', // Laravel 11 default
        'health',
        'healthz',
        'ping',

        // Dev tooling
        'telescope*',
        'horizon*',
        '_ignition*',
        '_debugbar*',

        // Admin dashboards — auth-only, AEO content is public-facing
        'admin*',
        'nova*',
        'filament*',

        // v0.7.0: e-commerce / auth flows. These pages either depend on
        // session state (cart contents, account dashboard) or carry no
        // SEO-relevant content (login forms). Sending them upstream wastes
        // audit budget and leaks per-user paths into the queue.
        'cart',
        'cart/*',
        'checkout',
        'checkout/*',
        'account/*',
        'profile/*',
        'login',
        'logout',
        'register',
        'password/*',
        'forgot-password*',
        'reset-password*',
    ];
}
