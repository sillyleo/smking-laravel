@if($cms->isRenderable())
    @php
        // The existing HTTP middleware renders these resolved facts in <head>.
        // This also supports host views that include smking::cms directly.
        $cmsPath = rtrim((string) config('smking.cms.base_path', '/blog'), '/')
            . ($cms->slug ? '/' . $cms->slug : '');
        if (\Smking\Laravel\Support\PathNormalizer::canonical(request()->path())
            === \Smking\Laravel\Support\PathNormalizer::canonical($cmsPath)) {
            request()->attributes->set('smking.cms.page', $cms);
        }
    @endphp
    {{-- Body remains the published HTML. Head metadata needs no AEO lookup. --}}

    @if(! empty($cms->bodyHtml))
        <div data-smking="cms">
            {!! $cms->bodyHtml !!}
        </div>
    @endif
@endif
