<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Khmer Dub' }}</title>
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
</head>
<body>
<nav class="topbar" aria-label="Account">
    <a class="brand" href="{{ route('dub.index') }}">Khmer Dub <span class="km">· បញ្ចូលសំឡេងខ្មែរ</span></a>
    <div class="account">
        <a href="{{ route('profile.edit') }}" title="Account · គណនី">{{ Auth::user()->name }}</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="link" type="submit">Log out · ចាកចេញ</button>
        </form>
    </div>
</nav>
<main>
    {{ $slot }}
</main>
{{ $scripts ?? '' }}
</body>
</html>
