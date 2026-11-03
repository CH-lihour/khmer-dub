<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Khmer Dub' }}</title>
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
</head>
<body>
<main class="narrow">
    <header>
        <h1>Khmer Dub <span class="km">· បញ្ចូលសំឡេងខ្មែរ</span></h1>
        <p class="lead">Khmer subtitles and a Khmer voice for your videos.<br>
            <span lang="km">អក្សររត់ និងសំឡេងខ្មែរ សម្រាប់វីដេអូរបស់អ្នក។</span></p>
    </header>
    <div class="card">
        {{ $slot }}
    </div>
</main>
</body>
</html>
