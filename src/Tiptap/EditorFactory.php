<?php

declare(strict_types=1);

namespace Smking\Laravel\Tiptap;

use Smking\Laravel\Tiptap\Nodes\Gallery;
use Throwable;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;
use Tiptap\Nodes\Image;

/**
 * Central place to build a Tiptap editor configured with the same
 * extension list the SaaS uses for /write content. Whenever the SaaS
 * registers a new extension in apps/web/src/components/tiptap-templates/
 * simple/simple-editor.tsx, mirror the addition here so customer
 * renders don't drop the new node type to plain text.
 *
 * Usage:
 *   $factory = app(EditorFactory::class);
 *   $html = $factory->renderHtml($jsonFromSaas);
 *
 * Bound as a singleton in SmkingServiceProvider so each request reuses
 * the extension instances.
 */
class EditorFactory
{
    /**
     * Build a fresh Tiptap Editor with the SmKing extension stack.
     */
    public function make(): Editor
    {
        return new Editor([
            'extensions' => [
                new StarterKit(),
                new Image(),
                new Link([
                    'openOnClick' => false,
                    'autolink' => true,
                    'HTMLAttributes' => [
                        'rel' => 'noopener noreferrer',
                        'target' => '_blank',
                    ],
                ]),
                new Gallery(),
            ],
        ]);
    }

    /**
     * Render a Tiptap ProseMirror JSON document (array or JSON string)
     * to HTML. Returns an empty string on render error so a malformed
     * payload from upstream never breaks the customer page render.
     *
     * @param  array<string, mixed>|string  $content
     */
    public function renderHtml(array|string $content): string
    {
        try {
            return $this->make()->setContent($content)->getHTML();
        } catch (Throwable) {
            return '';
        }
    }
}
