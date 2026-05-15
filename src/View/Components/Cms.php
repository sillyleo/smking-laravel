<?php

declare(strict_types=1);

namespace Smking\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Data\CmsPage;

/**
 * Blade component for embedding a CMS page body.
 *
 *   <x-smking-cms slug="hello" />
 *
 * Server-side fetches the published page, renders the Tiptap doc to
 * HTML via tiptap-php, and prints a `<article class="smk-cms">` shell.
 * If the page isn't published / SaaS unreachable / key invalid, the
 * component renders nothing — host page falls through.
 */
class Cms extends Component
{
    public CmsPage $cms;

    public function __construct(
        CmsClient $client,
        string $slug,
    ) {
        $this->cms = $client->forSlug($slug);
    }

    public function render(): View
    {
        return view('smking::cms');
    }
}
