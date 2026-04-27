@if ($aeo->isReady())
    @if ($aeo->jsonLd)
        <script type="application/ld+json" data-smking="aeo">{!! str_replace('</', '<\/', json_encode($aeo->jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !!}</script>
    @endif

    @if ($showSummary && $aeo->summaryHtml !== '')
        {!! $aeo->summaryHtml !!}
    @endif

    @if ($showFaq && $aeo->faqHtml !== '')
        {!! $aeo->faqHtml !!}
    @endif
@endif
