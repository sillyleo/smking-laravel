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

    @case('nav-taxonomy-list')
        @php
            $snapshot = is_array($props['snapshot'] ?? null) ? $props['snapshot'] : [];
            $layout = is_string($props['layout'] ?? null) ? $props['layout'] : 'list';
            $heading = is_string($props['heading'] ?? null) ? $props['heading'] : null;
            $viewAll = is_array($props['viewAll'] ?? null) ? $props['viewAll'] : null;
            $baseClass = 'smk-nav-taxonomy-list';
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

    @default
        <section class="smk-block smk-block--unknown" data-block-component="{{ $component }}" data-block-id="{{ $id }}"></section>
@endswitch
