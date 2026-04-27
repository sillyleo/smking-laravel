<?php

declare(strict_types=1);

namespace Smking\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Data\AeoResponse;

/**
 * Blade component for manual AEO injection.
 *
 * <x-smking-aeo path="/products/blue-widget" />
 * <x-smking-aeo :product-id="14" />
 *
 * Useful when auto-inject is disabled or when a page needs content from a
 * different path than the request URL.
 */
class Aeo extends Component
{
    public AeoResponse $aeo;

    public function __construct(
        AeoClient $client,
        ?string $path = null,
        ?int $productId = null,
        public bool $showFaq = true,
        public bool $showSummary = true,
    ) {
        if ($productId !== null) {
            $this->aeo = $client->forProductId($productId);
        } elseif ($path !== null) {
            $this->aeo = $client->forPath($path);
        } else {
            $this->aeo = AeoResponse::notFound();
        }
    }

    public function render(): View
    {
        return view('smking::aeo');
    }
}
