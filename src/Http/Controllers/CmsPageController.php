<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Illuminate\Http\Response;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Data\CmsPage;

/**
 * Response-aware entry point for customer-mounted Page Zero Blog routes.
 *
 * A Blade component can decide whether to emit content, but it cannot change
 * the outer Laravel response status. Resolving the CMS page before rendering
 * prevents an unknown slug from becoming an empty HTTP 200 response.
 */
final class CmsPageController
{
    public function __construct(
        private readonly CmsClient $client,
    ) {
    }

    public function __invoke(string $path = ''): Response
    {
        $cms = $this->client->forSlug($path);

        if ($cms->status === CmsPage::STATUS_NOT_FOUND) {
            abort(404);
        }

        if (! $cms->isRenderable()) {
            abort(503);
        }

        return response()->view('smking-cms-page', [
            'slug' => $path,
            'cms' => $cms,
        ]);
    }
}
