@if($cms->isReady())
    <article class="smk-cms" data-smking="cms">
        @if($cms->title)
            <h1 class="smk-cms__title">{{ $cms->title }}</h1>
        @endif
        {!! $cms->bodyHtml !!}
    </article>
@endif
