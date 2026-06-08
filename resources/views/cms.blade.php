@if($cms->isRenderable())
    {{-- Raw passthrough — bodyHtml is the full Plate-serialized output
         (Tailwind utility + slate-* hooks + data-slate-* attrs intact).
         No article shell, no cms-styles include: what the dashboard
         renders is what the customer page renders. Customer site can
         layer its own Tailwind / CSS to style `.slate-*` hooks.

         SEO meta + JSON-LD @graph are NO LONGER emitted here. The
         structured-data engine serves them via /api/v1/public/aeo, and the
         InjectAeo middleware injects them into <head> — where <title> /
         <meta> are actually honored. Blade has no React-19-style metadata
         hoisting, so emitting them in <body> here was dead markup that the
         host page's <head> defaults silently overrode. --}}

    @if(! empty($cms->bodyHtml))
        <div data-smking="cms">
            {!! $cms->bodyHtml !!}
        </div>
    @endif
@endif
