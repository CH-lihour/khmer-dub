<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.head', ['title' => $title ?? null])
</head>
<body>
<div class="welcome">
    <aside class="welcome-side">
        <span class="brand"><x-logo /> Khmer Dub</span>
        <p class="hello" lang="km">សួស្តី</p>
    </aside>
    <main class="welcome-form">
        <x-language />
        <div class="panel welcome-card">
            {{ $slot }}
        </div>
    </main>
</div>
</body>
</html>
