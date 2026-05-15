@if($cms->isReady())
    @if(! empty($cms->seo))
        @php($_smkingSeo = $cms->seo)
        {{-- SEO meta — emitted alongside the article body. Customer's
             host layout typically already has a <head> block; React 19
             style head hoisting doesn't exist in Blade, so we emit
             these here in-flow. Modern browsers + search engines tolerate
             meta tags appearing after <head> (they get hoisted by the
             parser), but if the customer wants strict <head> placement
             they should use <x-smking-meta /> in their actual <head>
             tag instead of relying on <x-smking-cms> for meta. --}}
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

    <article class="smk-cms" data-smking="cms">
        @if($cms->title)
            <h1 class="smk-cms__title">{{ $cms->title }}</h1>
        @endif
        {!! $cms->bodyHtml !!}
    </article>
@endif
