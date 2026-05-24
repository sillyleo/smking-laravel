@if($cms->isReady())
    {{-- Raw passthrough — bodyHtml is the full Plate-serialized output
         (Tailwind utility + slate-* hooks + data-slate-* attrs intact).
         No article shell, no cms-styles include: what the dashboard
         renders is what the customer page renders. Customer site can
         layer its own Tailwind / CSS to style `.slate-*` hooks. --}}

    @if(! empty($cms->seo))
        @php($_smkingSeo = $cms->seo)
        @if(! empty($_smkingSeo['title']))
            <title data-smking="cms">{{ $_smkingSeo['title'] }}</title>
        @endif
        @if(! empty($_smkingSeo['metaDescription']))
            <meta name="description" content="{{ $_smkingSeo['metaDescription'] }}" data-smking="cms">
        @endif
        @if(! empty($_smkingSeo['ogTitle']))
            <meta property="og:title" content="{{ $_smkingSeo['ogTitle'] }}" data-smking="cms">
        @endif
        @if(! empty($_smkingSeo['ogDescription']))
            <meta property="og:description" content="{{ $_smkingSeo['ogDescription'] }}" data-smking="cms">
        @endif
        @if(! empty($_smkingSeo['ogImageUrl']))
            <meta property="og:image" content="{{ $_smkingSeo['ogImageUrl'] }}" data-smking="cms">
        @endif
        @if(! empty($_smkingSeo['canonicalUrl']))
            <link rel="canonical" href="{{ $_smkingSeo['canonicalUrl'] }}" data-smking="cms">
        @endif
    @endif

    @if(! empty($cms->bodyHtml))
        <div data-smking="cms">
            {!! $cms->bodyHtml !!}
        </div>
    @endif
@endif
