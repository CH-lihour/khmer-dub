{{-- Overrides Laravel's plain error page (403, 404, 419, 500...) with the site's look. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.head', ['title' => trim($__env->yieldContent('title')).' · Khmer Dub'])
</head>
<body>
<div class="krama"></div>
<main class="wrap page">
    <div class="panel">
        <x-logo />
        <h1>@yield('message')</h1>
        <p class="hint">{{ __('Error') }} @yield('code')</p>
        <div class="actions">
            <a class="button" href="{{ url('/') }}">{{ __('Go to your videos') }}</a>
        </div>
    </div>
</main>
</body>
</html>
