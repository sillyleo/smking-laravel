@php
    $title = $title();
    $ogTitle = $ogTitle();
    $ogDescription = $ogDescription();
    $ogImageUrl = $ogImageUrl();
    $canonicalUrl = $canonicalUrl();
@endphp
@if ($title !== null)
<title data-smking="seo">{{ $title }}</title>
@endif
@if ($ogTitle !== null)
<meta property="og:title" content="{{ $ogTitle }}" data-smking="seo">
@endif
@if ($ogDescription !== null)
<meta property="og:description" content="{{ $ogDescription }}" data-smking="seo">
<meta name="twitter:description" content="{{ $ogDescription }}" data-smking="seo">
@endif
@if ($ogImageUrl !== null)
<meta property="og:image" content="{{ $ogImageUrl }}" data-smking="seo">
<meta name="twitter:image" content="{{ $ogImageUrl }}" data-smking="seo">
@endif
@if ($canonicalUrl !== null)
<link rel="canonical" href="{{ $canonicalUrl }}" data-smking="seo">
@endif
