@if($cms->isReady())
    @php
        $_smkingThemeMode = config('smking.cms.theme_mode', 'css');
        $_smkingIsModeB = $_smkingThemeMode === 'tailwind-prose';
    @endphp

    {{-- Mode A only: inline canonical CSS so customer pages render styled
         without any stylesheet wiring. Mode B opts in to Tailwind +
         @tailwindcss/typography and skips this. The legacy
         `inline_styles` flag stays gated on Mode A. --}}
    @if(! $_smkingIsModeB && (bool) config('smking.cms.inline_styles', true))
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

    @if($_smkingIsModeB)
        {{-- Mode B: customer's @tailwindcss/typography plugin styles
             the article. `not-prose` markers inside each block-prose
             partial reset prose styling around structured blocks
             (hero / nav / carousel / search). --}}
        <article class="prose lg:prose-xl dark:prose-invert mx-auto" data-smking="cms">
            @if($cms->title)
                <h1>{{ $cms->title }}</h1>
            @endif
            @if(is_array($cms->blocks))
                @foreach($cms->blocks as $_smkingBlock)
                    @include('smking::block-prose', ['block' => $_smkingBlock])
                @endforeach
            @elseif($cms->bodyHtml)
                {!! $cms->bodyHtml !!}
            @endif
        </article>
    @else
        <article class="smk-cms" data-smking="cms">
            @if($cms->title)
                <h1 class="smk-cms__title">{{ $cms->title }}</h1>
            @endif
            {{-- v0.14.0+ substrate v2: `$cms->blocks` is the canonical
                 render path (Block[] dispatched component-by-component).
                 Legacy pre-pivot SaaS deployments fall back to `bodyHtml`. --}}
            @if(is_array($cms->blocks))
                @foreach($cms->blocks as $_smkingBlock)
                    @include('smking::block', ['block' => $_smkingBlock])
                @endforeach
            @elseif($cms->bodyHtml)
                {!! $cms->bodyHtml !!}
            @endif
        </article>
    @endif
@endif
