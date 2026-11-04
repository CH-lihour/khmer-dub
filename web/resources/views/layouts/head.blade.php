{{-- What every page has in its <head>. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Khmer Dub' }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="preload" href="{{ asset('fonts/kantumruy-pro-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/kantumruy-pro-khmer-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
