{{-- Single-block dispatcher — receives one `$block` array
     (`['component', 'id', 'props']`) and emits the appropriate
     markup. Trust boundary: `$block['props']['html']` for article
     blocks is server-rendered HTML produced by Plate's `serializeHtml`
     on the SaaS side; safe to print with `{!!` since the SaaS is the
     authoring authority for that surface.

     Unknown components emit an empty marker so customer pages don't
     500 if SaaS ships a new block type before SDK catches up. --}}

@php
    $component = is_string($block['component'] ?? null) ? $block['component'] : 'unknown';
    $id = is_string($block['id'] ?? null) ? $block['id'] : '';
    $props = is_array($block['props'] ?? null) ? $block['props'] : [];
@endphp

@switch($component)
    @case('article')
        <div class="smk-block smk-block--article" data-block-id="{{ $id }}">
            {!! $props['html'] ?? '' !!}
        </div>
        @break

    @case('hero')
        <section class="smk-block smk-block--hero smk-hero" data-block-id="{{ $id }}">
            @if(! empty($props['image']['url']))
                <img src="{{ $props['image']['url'] }}" alt="{{ $props['image']['alt'] ?? '' }}" class="smk-hero__image">
            @endif
            @if(! empty($props['title']))
                <h2 class="smk-hero__title">{{ $props['title'] }}</h2>
            @endif
            @if(! empty($props['subtitle']))
                <p class="smk-hero__subtitle">{{ $props['subtitle'] }}</p>
            @endif
            @if(! empty($props['cta']['href']) && ! empty($props['cta']['label']))
                <a class="smk-hero__cta" href="{{ $props['cta']['href'] }}">{{ $props['cta']['label'] }}</a>
            @endif
        </section>
        @break

    @case('nav-recent-posts')
    @case('nav-taxonomy-list')
        @php
            $snapshot = is_array($props['snapshot'] ?? null) ? $props['snapshot'] : [];
            $layout = is_string($props['layout'] ?? null) ? $props['layout'] : 'list';
            $heading = is_string($props['heading'] ?? null) ? $props['heading'] : null;
            $viewAll = is_array($props['viewAll'] ?? null) ? $props['viewAll'] : null;
            $baseClass = $component === 'nav-recent-posts' ? 'smk-nav-recent-posts' : 'smk-nav-taxonomy-list';
        @endphp
        <section class="smk-block smk-block--{{ $component }} smk-nav {{ $baseClass }}"
                 data-block-id="{{ $id }}"
                 data-block-component="{{ $component }}"
                 data-layout="{{ $layout }}">
            @if($heading)
                <h2 class="smk-nav__heading">{{ $heading }}</h2>
            @endif
            @if(count($snapshot) === 0)
                <p class="smk-nav__empty">No posts yet.</p>
            @else
                <ul class="smk-nav__list">
                    @foreach($snapshot as $_entry)
                        @php
                            $entrySlug = is_string($_entry['slug'] ?? null) ? $_entry['slug'] : '';
                            $entryTitle = is_string($_entry['title'] ?? null) ? $_entry['title'] : '';
                            $entryExcerpt = is_string($_entry['excerpt'] ?? null) ? $_entry['excerpt'] : null;
                            $entryImage = is_string($_entry['featuredImageUrl'] ?? null) ? $_entry['featuredImageUrl'] : null;
                            $entryDate = is_string($_entry['publishedAt'] ?? null) ? $_entry['publishedAt'] : null;
                        @endphp
                        <li class="smk-nav__item{{ $loop->first && $layout === 'featured' ? ' smk-nav__item--featured' : '' }}">
                            <a href="/{{ ltrim($entrySlug, '/') }}" class="smk-nav__link">
                                @if($entryImage)
                                    <img src="{{ $entryImage }}" alt="" class="smk-nav__thumb" loading="lazy">
                                @endif
                                <div class="smk-nav__meta">
                                    <h3 class="smk-nav__title">{{ $entryTitle !== '' ? $entryTitle : $entrySlug }}</h3>
                                    @if($entryExcerpt)
                                        <p class="smk-nav__excerpt">{{ $entryExcerpt }}</p>
                                    @endif
                                    @if($entryDate)
                                        <time class="smk-nav__date" datetime="{{ $entryDate }}">
                                            {{ \Illuminate\Support\Carbon::parse($entryDate)->toFormattedDateString() }}
                                        </time>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($viewAll && ! empty($viewAll['href']) && ! empty($viewAll['label']))
                <a class="smk-nav__view-all" href="{{ $viewAll['href'] }}">{{ $viewAll['label'] }}</a>
            @endif
        </section>
        @break

    @case('nav-search')
        @php
            $index = is_array($props['index'] ?? null) ? $props['index'] : [];
            $placeholder = is_string($props['placeholder'] ?? null) && trim($props['placeholder']) !== ''
                ? $props['placeholder']
                : 'Search…';
            // Escape `</` so the JSON payload can't terminate the surrounding
            // <script> tag — same hardening as the Next SDK. Author input in
            // title/excerpt is JSON-encoded and the </script> sequence is
            // converted to </script.
            $indexJson = str_replace('<', '\\u003c', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @endphp
        <section class="smk-block smk-block--nav-search smk-nav-search" data-block-id="{{ $id }}">
            <div class="smk-nav-search__shell">
                <input type="search"
                       data-smk-search-input
                       placeholder="{{ $placeholder }}"
                       class="smk-nav-search__input">
                <ul data-smk-search-results hidden class="smk-nav-search__list"></ul>
                <p data-smk-search-empty hidden class="smk-nav-search__empty">No matches.</p>
            </div>
            <script>(function(){var s=document.currentScript;var r=s&&s.previousElementSibling;if(!r)return;var i=r.querySelector('[data-smk-search-input]');var u=r.querySelector('[data-smk-search-results]');var e=r.querySelector('[data-smk-search-empty]');var idx={!! $indexJson !!};function up(){var q=(i.value||'').trim().toLowerCase();u.textContent='';if(!q){u.hidden=true;e.hidden=true;return}var m=idx.filter(function(x){return((x.title||'')+' '+(x.excerpt||'')+' '+(x.slug||'')).toLowerCase().indexOf(q)>=0}).slice(0,20);if(m.length===0){u.hidden=true;e.hidden=false;return}e.hidden=true;u.hidden=false;m.forEach(function(x){var li=document.createElement('li');li.className='smk-nav-search__result';var a=document.createElement('a');a.href='/'+x.slug;a.textContent=x.title||x.slug;li.appendChild(a);u.appendChild(li)})}i.addEventListener('input',up)})();</script>
        </section>
        @break

    @case('carousel')
        {{-- v0.14.0 ships markup only; the Web Component runtime that
             upgrades `<smking-carousel>` into a swipe/nav/dots widget
             will land in v0.14.1 alongside `<x-smking-elements />`.
             Until then the slides render as a static image list — usable
             but not interactive. --}}
        <section class="smk-block smk-block--carousel smk-carousel" data-block-id="{{ $id }}" data-aspect="{{ $props['aspectRatio'] ?? '16:9' }}">
            <div class="smk-carousel__viewport">
                @foreach(($props['slides'] ?? []) as $_slide)
                    <div class="smk-carousel__slide">
                        @if(! empty($_slide['imageUrl']))
                            <img src="{{ $_slide['imageUrl'] }}" alt="" class="smk-carousel__image" loading="lazy">
                        @endif
                        @if(! empty($_slide['caption']))
                            <div class="smk-carousel__caption">{{ $_slide['caption'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
        @break

    @case('category-nav')
        @php
            $items = is_array($props['items'] ?? null) ? $props['items'] : [];
            $align = ($props['align'] ?? null) === 'center' ? 'center' : 'left';
            $items = array_values(array_filter($items, function ($it) {
                return is_array($it)
                    && is_string($it['label'] ?? null) && trim($it['label']) !== ''
                    && is_string($it['href'] ?? null) && trim($it['href']) !== '';
            }));
        @endphp
        <nav class="smk-block smk-block--category-nav smk-cat-nav"
             data-block-id="{{ $id }}"
             data-block-component="category-nav"
             data-align="{{ $align }}"
             aria-label="Categories">
            @if(count($items) === 0)
                <p class="smk-cat-nav__empty">No categories yet.</p>
            @else
                <ul class="smk-cat-nav__list">
                    @foreach($items as $_item)
                        <li class="smk-cat-nav__item">
                            <a class="smk-cat-nav__link" href="{{ $_item['href'] }}">{{ $_item['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>
        @break

    @default
        <section class="smk-block smk-block--unknown" data-block-component="{{ $component }}" data-block-id="{{ $id }}"></section>
@endswitch
