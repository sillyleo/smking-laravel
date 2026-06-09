{{-- One-time-mount SDK runtime assets. See View/Components/Runtime.php. --}}
<link rel="stylesheet" href="{{ $baseUrl }}/api/v1/public/runtime.css">
@if($apiKey)
<link rel="stylesheet" href="{{ $baseUrl }}/api/v1/public/theme.css?key={{ $apiKey }}">
@endif
<script src="{{ $baseUrl }}/api/v1/public/runtime.js" async></script>
