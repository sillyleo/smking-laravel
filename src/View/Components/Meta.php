<?php

declare(strict_types=1);

namespace Smking\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Data\SeoMeta;

/**
 * Blade component that renders SEO meta tags inside <head>.
 *
 *   <head>
 *     <x-smking-meta :path="request()->path()" :fallback-title="$page->title" />
 *   </head>
 *
 * Render mirror of `getSmkingMetadata()` from `@smking/next`. Emits
 * <title>, og:title / description / image, and rel=canonical only when
 * the API has a value for that field — otherwise falls back to the
 * `fallback*` props so the host page never ends up with empty tags.
 *
 * Disable auto-injection (`config/smking.php` → `auto_inject` false) when
 * using this component, otherwise the middleware will also try to write
 * head tags and you'll get duplicates.
 */
class Meta extends Component
{
    public ?SeoMeta $seo;

    public function __construct(
        AeoClient $client,
        ?string $path = null,
        ?int $productId = null,
        public ?string $fallbackTitle = null,
        public ?string $fallbackOgTitle = null,
        public ?string $fallbackOgDescription = null,
        public ?string $fallbackOgImageUrl = null,
        public ?string $fallbackCanonicalUrl = null,
    ) {
        if ($productId !== null) {
            $this->seo = $client->forProductId($productId)->seo;
        } elseif ($path !== null) {
            $this->seo = $client->forPath($path)->seo;
        } else {
            $this->seo = null;
        }
    }

    public function title(): ?string
    {
        return $this->seo?->title ?? $this->fallbackTitle;
    }

    public function ogTitle(): ?string
    {
        return $this->seo?->ogTitle ?? $this->fallbackOgTitle ?? $this->title();
    }

    public function ogDescription(): ?string
    {
        return $this->seo?->ogDescription ?? $this->fallbackOgDescription;
    }

    public function ogImageUrl(): ?string
    {
        return $this->seo?->ogImageUrl ?? $this->fallbackOgImageUrl;
    }

    public function canonicalUrl(): ?string
    {
        return $this->seo?->canonicalUrl ?? $this->fallbackCanonicalUrl;
    }

    public function render(): View
    {
        return view('smking::meta');
    }
}
