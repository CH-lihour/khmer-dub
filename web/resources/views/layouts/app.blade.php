<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.head', ['title' => $title ?? null])
</head>
<body>
<header class="site">
    <div class="krama"></div>
    <nav class="topbar wrap" aria-label="{{ __('Account') }}">
        <a class="brand" href="{{ route('dub.index') }}" aria-label="Khmer Dub"><x-logo /> <span class="brand-name">Khmer Dub</span></a>
        <div class="account">
            <x-language />
            <a class="person" href="{{ route('profile.edit') }}" title="{{ Auth::user()->name }}" aria-label="{{ __('Account') }}">
                <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="quiet" type="submit">{{ __('Log out') }}</button>
            </form>
        </div>
    </nav>
</header>
<main>
    {{ $slot }}
</main>
{{ $scripts ?? '' }}
</body>
</html>
