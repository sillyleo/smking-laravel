<?php

declare(strict_types=1);

namespace Smking\Laravel\Tiptap\Nodes;

use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * SmKing Gallery node — PHP mirror of the SaaS gallery-node-extension.ts.
 *
 * Markup MUST stay byte-equal to:
 *   - apps/web/src/components/tiptap-node/gallery-node/gallery-node-extension.ts (SaaS authoring)
 *   - packages/smking-next/src/components/cms-nodes/gallery-node.tsx (Next SDK render)
 *
 * If you change ANY attribute / class / nesting here, change it in the
 * other two and bump version on all three SDKs together. Customer site
 * CSS targets `.smk-gallery`, `.smk-gallery--{layout}`, `.smk-gallery__item`
 * — those names are public API.
 */
class Gallery extends Node
{
    public static $name = 'gallery';

    public static $priority = 100;

    public function addOptions()
    {
        return [
            'HTMLAttributes' => [],
        ];
    }

    public function addAttributes()
    {
        return [
            // images: stored as JSON-encoded array of {url, alt, caption?}
            // on data-images so the renderHTML output round-trips through
            // parseHTML on the SaaS authoring side without loss.
            'images' => [
                'default' => [],
                'parseHTML' => function ($DOMNode) {
                    $raw = $DOMNode->getAttribute('data-images');
                    if ($raw === '' || $raw === null) {
                        return [];
                    }
                    $decoded = json_decode($raw, true);
                    return is_array($decoded) ? $decoded : [];
                },
                'renderHTML' => function ($attributes) {
                    $images = $attributes->images ?? [];
                    return [
                        'data-images' => json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                },
            ],
            'layout' => [
                'default' => 'grid',
                'parseHTML' => fn ($DOMNode) => $DOMNode->getAttribute('data-layout') ?: 'grid',
                'renderHTML' => fn ($attributes) => [
                    'data-layout' => $attributes->layout ?? 'grid',
                ],
            ],
            'columns' => [
                'default' => 3,
                'parseHTML' => function ($DOMNode) {
                    $value = $DOMNode->getAttribute('data-columns');
                    if ($value === '' || $value === null) {
                        return 3;
                    }
                    $n = (int) $value;
                    return $n > 0 ? $n : 3;
                },
                'renderHTML' => fn ($attributes) => [
                    'data-columns' => (string) ($attributes->columns ?? 3),
                ],
            ],
        ];
    }

    public function parseHTML()
    {
        return [
            ['tag' => 'div[data-type="gallery"]'],
        ];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        // Just the wrapper open/close. Inner figures are emitted by
        // renderText() — see comment there for why this two-step works.
        $layout = $node->attrs->layout ?? 'grid';

        return [
            'div',
            HTML::mergeAttributes(
                [
                    'data-type' => 'gallery',
                    'class' => 'smk-gallery smk-gallery--'.$layout,
                ],
                $HTMLAttributes,
            ),
            0,
        ];
    }

    /**
     * tiptap-php's DOMSerializer renders atom nodes as
     *   <opening-tag>{renderText()}</closing-tag>
     * and passes renderText output through *unescaped* (DOMSerializer
     * line ~75). Our Gallery is an atom (no node.content) but needs
     * multiple figure children — we synthesize them here as a single
     * HTML string. Markup matches the SaaS gallery-node-extension.ts
     * + smking-next gallery-node.tsx render exactly.
     *
     * Trade-off: callers of `(new Editor)->setContent($json)->getText()`
     * will see this HTML string in their text output for gallery nodes.
     * That's not ideal but acceptable: a gallery has no meaningful
     * plain-text representation, and tiptap-php has no separate hook
     * for "atom inner HTML vs atom inner text".
     */
    public function renderText($node)
    {
        $images = $node->attrs->images ?? [];
        if (! is_array($images) && ! is_iterable($images)) {
            return '';
        }

        $html = '';
        foreach ($images as $img) {
            $img = is_object($img) ? (array) $img : $img;
            if (! is_array($img) || ! isset($img['url'])) {
                continue;
            }
            $url = htmlspecialchars((string) $img['url'], ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars(isset($img['alt']) ? (string) $img['alt'] : '', ENT_QUOTES, 'UTF-8');
            $html .= '<figure class="smk-gallery__item">';
            $html .= '<img src="'.$url.'" alt="'.$alt.'" loading="lazy">';
            if (isset($img['caption']) && $img['caption'] !== '') {
                $caption = htmlspecialchars((string) $img['caption'], ENT_QUOTES, 'UTF-8');
                $html .= '<figcaption>'.$caption.'</figcaption>';
            }
            $html .= '</figure>';
        }

        return $html;
    }
}
