<?php

declare(strict_types=1);

namespace Smking\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Blade component for one-time-mount of saas-served SDK runtime assets.
 *
 *   <x-smking-runtime />
 *
 * Place in the root layout `<head>`. Emits `<link>` + `<script async>`
 * pointing at the saas's `/api/v1/public/runtime.{css,js}` endpoints.
 * Browser caches both per saas-controlled headers (stale-while-revalidate
 * in production); subsequent `<x-smking-cms>` renders re-use the cached
 * asset.
 */
class Runtime extends Component
{
    public string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $configured = config('smking.base_url') ?? 'https://smking.app';
        $this->baseUrl = rtrim($baseUrl ?? $configured, '/');
    }

    public function render(): View
    {
        return view('smking::runtime');
    }
}
