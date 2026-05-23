{{--
  Mode B (theme_mode='tailwind-prose') single-block dispatcher.
  Mirror of smking::block but emits Tailwind utility classes instead
  of `.smk-*` namespace. Wrapper article carries `prose` for typography
  defaults; each block uses `not-prose` to reset around structured UI
  (hero/nav/carousel/search) and re-applies Tailwind utility styling.

  Required Tailwind safelist (the wizard adds these on install):
    prose / prose-invert / lg:prose-xl / dark:prose-invert / not-prose
    mx-auto / my-* / mt-* / mb-* / px-* / py-* / pt-* / pb-* / p-*
    text-* / font-* / tracking-* / leading-*
    bg-* / border / border-* / rounded-*
    grid / flex / gap-* / items-* / justify-* / space-y-*
    w-* / h-* / aspect-*

  Trust boundary same as smking::block — article HTML is pre-rendered
  by Plate serializeHtml on the SaaS side; safe to print with `{!!`.
--}}

@php
    $component = is_string($block['component'] ?? null) ? $block['component'] : 'unknown';
    $id = is_string($block['id'] ?? null) ? $block['id'] : '';
    $props = is_array($block['props'] ?? null) ? $block['props'] : [];
@endphp

@switch($component)
    @case('article')
        {{-- Article HTML inherits `prose` from the outer wrapper — no
             extra class needed. --}}
        <div data-block-id="{{ $id }}">{!! $props['html'] ?? '' !!}</div>
        @break

    @case('hero')
        <section class="not-prose my-12 text-center bg-zinc-50 dark:bg-zinc-900 py-16 px-6 rounded-lg" data-block-id="{{ $id }}">
            @if(! empty($props['image']['url']))
                <img src="{{ $props['image']['url'] }}" alt="{{ $props['image']['alt'] ?? '' }}" class="mx-auto mb-8 rounded-lg max-w-full h-auto" loading="lazy">
            @endif
            @if(! empty($props['title']))
                <h2 class="text-4xl md:text-5xl font-serif font-extrabold tracking-tight leading-tight mb-4">{{ $props['title'] }}</h2>
            @endif
            @if(! empty($props['subtitle']))
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-6">{{ $props['subtitle'] }}</p>
            @endif
            @if(! empty($props['cta']['href']) && ! empty($props['cta']['label']))
                <a class="inline-block mt-8 px-6 py-3 bg-zinc-900 dark:bg-zinc-100 text-zinc-100 dark:text-zinc-900 rounded font-medium" href="{{ $props['cta']['href'] }}">{{ $props['cta']['label'] }}</a>
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
            $listClass = match ($layout) {
                'grid' => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 list-none p-0 m-0',
                'carousel' => 'grid grid-flow-col auto-cols-[70%] md:auto-cols-[40%] gap-4 list-none p-0 m-0 overflow-x-auto snap-x snap-mandatory',
                default => 'flex flex-col list-none p-0 m-0',
            };
        @endphp
        <section class="not-prose my-10 border-t border-zinc-200 dark:border-zinc-800 pt-8" data-block-id="{{ $id }}">
            @if($heading)
                <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400 mb-6">{{ $heading }}</h2>
            @endif
            @if(count($snapshot) === 0)
                <p class="text-zinc-400 italic py-4">No posts yet.</p>
            @else
                <ul class="{{ $listClass }}">
                    @foreach($snapshot as $_entry)
                        @php
                            $entrySlug = is_string($_entry['slug'] ?? null) ? $_entry['slug'] : '';
                            $entryTitle = is_string($_entry['title'] ?? null) ? $_entry['title'] : '';
                            $entryExcerpt = is_string($_entry['excerpt'] ?? null) ? $_entry['excerpt'] : null;
                            $entryImage = is_string($_entry['featuredImageUrl'] ?? null) ? $_entry['featuredImageUrl'] : null;
                            $entryDate = is_string($_entry['publishedAt'] ?? null) ? $_entry['publishedAt'] : null;
                            $itemClass = match ($layout) {
                                'grid' => 'group',
                                'carousel' => 'snap-start bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg overflow-hidden',
                                default => 'border-t border-zinc-200 dark:border-zinc-800 first:border-t-0',
                            };
                            $linkClass = match ($layout) {
                                'grid' => 'flex flex-col bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-lg overflow-hidden no-underline text-inherit hover:-translate-y-0.5 hover:shadow-lg transition',
                                'carousel' => 'flex flex-col no-underline text-inherit',
                                default => 'flex gap-5 items-center py-5 no-underline text-inherit hover:opacity-80 transition',
                            };
                            $thumbClass = match ($layout) {
                                'grid', 'carousel' => 'w-full aspect-[16/10] object-cover bg-zinc-100 dark:bg-zinc-900',
                                default => 'w-24 h-24 object-cover rounded shrink-0 bg-zinc-100 dark:bg-zinc-900',
                            };
                            $metaClass = $layout === 'list' ? 'flex-1 min-w-0' : 'p-4';
                        @endphp
                        <li class="{{ $itemClass }}">
                            <a href="/{{ ltrim($entrySlug, '/') }}" class="{{ $linkClass }}">
                                @if($entryImage)
                                    <img src="{{ $entryImage }}" alt="" class="{{ $thumbClass }}" loading="lazy">
                                @endif
                                <div class="{{ $metaClass }}">
                                    <h3 class="text-lg font-serif font-bold leading-snug mb-2 tracking-tight">{{ $entryTitle !== '' ? $entryTitle : $entrySlug }}</h3>
                                    @if($entryExcerpt)
                                        <p class="text-sm text-zinc-500 dark:text-zinc-400 line-clamp-2 leading-relaxed">{{ $entryExcerpt }}</p>
                                    @endif
                                    @if($entryDate)
                                        <time class="block text-xs text-zinc-400 mt-2 uppercase tracking-wider" datetime="{{ $entryDate }}">
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
                <a class="inline-flex items-center gap-1 mt-6 text-sm font-semibold text-zinc-900 dark:text-zinc-100 no-underline hover:underline" href="{{ $viewAll['href'] }}">
                    {{ $viewAll['label'] }} <span aria-hidden>→</span>
                </a>
            @endif
        </section>
        @break

    @case('nav-search')
        @php
            $index = is_array($props['index'] ?? null) ? $props['index'] : [];
            $placeholder = is_string($props['placeholder'] ?? null) && trim($props['placeholder']) !== ''
                ? $props['placeholder']
                : 'Search…';
            $indexJson = str_replace('<', '\\u003c', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @endphp
        <section class="not-prose my-8 border-t border-zinc-200 dark:border-zinc-800 pt-6" data-block-id="{{ $id }}">
            <div class="relative">
                <input
                    type="search"
                    data-smk-search-input
                    placeholder="{{ $placeholder }}"
                    class="w-full px-4 py-3 border border-zinc-200 dark:border-zinc-800 rounded-lg bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 text-base focus:outline-none focus:border-zinc-900 dark:focus:border-zinc-100 focus:ring-4 focus:ring-zinc-900/10 dark:focus:ring-zinc-100/10 transition">
                <ul data-smk-search-results hidden class="list-none p-0 mt-3 border border-zinc-200 dark:border-zinc-800 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden shadow-lg"></ul>
                <p data-smk-search-empty hidden class="text-zinc-400 pt-4 text-sm">No matches.</p>
            </div>
            <script>(function(){var s=document.currentScript;var r=s&&s.previousElementSibling;if(!r)return;var i=r.querySelector('[data-smk-search-input]');var u=r.querySelector('[data-smk-search-results]');var e=r.querySelector('[data-smk-search-empty]');var idx={!! $indexJson !!};function up(){var q=(i.value||'').trim().toLowerCase();u.textContent='';if(!q){u.hidden=true;e.hidden=true;return}var m=idx.filter(function(x){return((x.title||'')+' '+(x.excerpt||'')+' '+(x.slug||'')).toLowerCase().indexOf(q)>=0}).slice(0,20);if(m.length===0){u.hidden=true;e.hidden=false;return}e.hidden=true;u.hidden=false;m.forEach(function(x){var li=document.createElement('li');li.className='border-t border-zinc-200 dark:border-zinc-800 first:border-t-0';var a=document.createElement('a');a.href='/'+x.slug;a.className='block px-4 py-3 text-zinc-900 dark:text-zinc-100 no-underline hover:bg-zinc-50 dark:hover:bg-zinc-900';a.textContent=x.title||x.slug;li.appendChild(a);u.appendChild(li)})}i.addEventListener('input',up)})();</script>
        </section>
        @break

    @case('carousel')
        @php
            $aspect = $props['aspectRatio'] ?? '16:9';
            $aspectClass = match ($aspect) {
                '4:3' => 'aspect-[4/3]',
                '1:1' => 'aspect-square',
                '3:4' => 'aspect-[3/4]',
                '9:16' => 'aspect-[9/16]',
                default => 'aspect-video',
            };
        @endphp
        <section class="not-prose my-8" data-block-id="{{ $id }}">
            <div class="grid grid-flow-col auto-cols-[80%] md:auto-cols-[60%] gap-4 overflow-x-auto snap-x snap-mandatory pb-2 -mx-6 px-6">
                @foreach(($props['slides'] ?? []) as $_slide)
                    <div class="snap-start bg-zinc-100 dark:bg-zinc-900 rounded-lg overflow-hidden">
                        @if(! empty($_slide['imageUrl']))
                            <img src="{{ $_slide['imageUrl'] }}" alt="" class="w-full {{ $aspectClass }} object-cover block" loading="lazy">
                        @endif
                        @if(! empty($_slide['caption']))
                            <div class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $_slide['caption'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
        @break

    @default
        {{-- Forward-compat: unknown component renders nothing in Mode B. --}}
@endswitch
