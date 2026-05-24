@if($cms->isReady())
    {{-- v0.16+ canonical CSS — Mode A is the only supported mode
         (Mode B / `tailwind-prose` removed in v0.16). Customer who
         wants Tailwind typography wrap can put a `<article class="prose">`
         around `<x-smking-cms>` themselves. --}}
    @if((bool) config('smking.cms.inline_styles', true))
        @include('smking::cms-styles')
    @endif

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

    @if(! empty($cms->bodyHtml))
        {{-- v0.16+ canonical path — SaaS publish handler ran Plate's
             `serializeHtml` on the full page (with nav-* snapshots
             injected) and ships the result. We echo it inside the
             canonical `.smk-cms` wrapper. --}}
        <article class="smk-cms" data-smking="cms">
            {!! $cms->bodyHtml !!}
        </article>
    @elseif(is_array($cms->blocks))
        {{-- Legacy fallback — SaaS pre-v0.16 returns `blocks` only.
             Dispatch each block via the per-component partial. --}}
        <article class="smk-cms" data-smking="cms">
            @if($cms->title)
                <h1 class="smk-cms__title">{{ $cms->title }}</h1>
            @endif
            @foreach($cms->blocks as $_smkingBlock)
                @include('smking::block', ['block' => $_smkingBlock])
            @endforeach
        </article>
    @endif
@endif
