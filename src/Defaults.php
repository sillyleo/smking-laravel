<?php

declare(strict_types=1);

namespace Smking\Laravel;

/**
 * Public defaults that customers can spread into their config to opt into
 * the package's recommended baseline. Two const arrays:
 *
 *   - EXCEPT_PATTERNS — **technical** routes that every Laravel site has
 *     in the same place because the framework or a known package owns them
 *     (api/*, livewire/*, telescope*, horizon*, etc.). Safe to ship as
 *     default because they don't depend on the customer's business URLs.
 *
 *   - SUGGESTED_BUSINESS_EXCEPT — common patterns for **business** routes
 *     (cart, checkout, account, login, password reset, …) that every
 *     e-commerce / auth site has *some version of* but where the actual
 *     URL varies per customer (`/cart` vs `/購物車` vs `/shopping-bag`).
 *     **Not enabled by default.** Customers should review their own
 *     `php artisan route:list` and either spread this const or write their
 *     own list.
 *
 * @api this class is part of the public surface — renaming constants is a
 *      breaking change.
 */
final class Defaults
{
    /**
     * Path patterns that are safe defaults across **all** Laravel sites
     * because the framework or a known package registers them. Used as
     * the default value of `config('smking.except')`.
     *
     * Categories:
     *   - Laravel API conventions (`api/*`, version prefixes, GraphQL,
     *     OAuth and webhook callbacks)
     *   - Realtime / SPA component endpoints (Livewire diff payloads
     *     aren't HTML and would be corrupted by injection)
     *   - HTTP health check standards (Laravel 11's `up`, k8s/LB probes)
     *   - Dev tooling (Telescope, Horizon, debug bars, error overlays)
     *   - Admin dashboard packages (Nova, Filament — register their own
     *     `/nova`, `/filament` paths)
     *
     * NOT included (use {@see SUGGESTED_BUSINESS_EXCEPT} for templates):
     *   - cart / checkout / account / profile / login / register / etc.
     *     URL naming for these varies wildly per customer; SDK can't
     *     assume `/cart` over `/購物車` over `/shopping-bag`.
     *
     * Re kept after adversarial review:
     *   - `admin*` is included — strong Laravel convention (docs and
     *     >90% of installs use this exact path). Was in the v0.6.x
     *     baseline; removing it was a regression. If your admin lives
     *     at a different path, override `except` in your config.
     *
     * @var list<string>
     */
    public const EXCEPT_PATTERNS = [
        // Laravel API conventions
        'api/*',
        'v1/*',
        'v2/*',
        'graphql',
        'webhooks/*',
        'oauth/*',

        // Realtime / SPA component endpoints
        'livewire/*',

        // HTTP health check standards
        'up', // Laravel 11 default
        'health',
        'healthz',
        'ping',

        // Dev tooling — these packages register their routes at fixed paths
        'telescope*',
        'horizon*',
        '_ignition*',
        '_debugbar*',

        // Admin dashboards
        'admin*',  // strong Laravel convention; restored after v0.7.0 review
        'nova*',
        'filament*',
    ];

    /**
     * Suggested business-route patterns for e-commerce + auth sites.
     * **Not used by default.** This is a copy-paste template; review
     * `php artisan route:list` and adapt to your actual URL naming
     * before adding to `config('smking.except')`.
     *
     * Why these aren't defaults:
     *   - `/cart` vs `/購物車` vs `/shopping-bag` — naming varies
     *   - `/account` vs `/我的帳戶` vs `/dashboard` — naming varies
     *   - `/login` vs `/sign-in` vs `/auth/login` — naming varies
     *   - Some sites genuinely want AEO on their checkout for marketing
     *     (e.g. promo banners on the cart page) — opt-in is correct
     *
     * Typical customer config after spreading:
     *
     *     'except' => [
     *         ...\Smking\Laravel\Defaults::EXCEPT_PATTERNS,
     *         ...\Smking\Laravel\Defaults::SUGGESTED_BUSINESS_EXCEPT,
     *         'my/store-specific/path',
     *     ],
     *
     * Both root and wildcard variants included because Laravel's
     * `Request::is('account/*')` does NOT match a bare `/account` —
     * you need both patterns to cover dashboard root + sub-routes.
     *
     * @var list<string>
     */
    public const SUGGESTED_BUSINESS_EXCEPT = [
        // Cart + checkout (rename if your site uses different URLs)
        'cart',
        'cart/*',
        'checkout',
        'checkout/*',
        'basket',
        'basket/*',

        // Account / dashboard / profile
        'account',
        'account/*',
        'profile',
        'profile/*',
        'dashboard',
        'dashboard/*',

        // Auth flows
        'login',
        'logout',
        'register',
        'sign-in',
        'sign-out',
        'sign-up',

        // Password management
        'password',
        'password/*',
        'forgot-password',
        'forgot-password/*',
        'reset-password',
        'reset-password/*',
    ];
}
