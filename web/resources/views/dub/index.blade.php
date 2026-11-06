@php
    $statusLabel = ['queued' => __('Queued'), 'processing' => __('Processing'), 'review' => __('Needs review'), 'done' => __('Done'), 'failed' => __('Failed')];
    $texts = [
        'uploading' => __('Uploading'),
        'review' => __('Review'),
        'off' => __('Off'),
        'justNow' => __('just now'),
        'chooseFile' => __('Choose a video file.'),
        'signedOut' => __('You are signed out. Reload the page and log in again.'),
        'otherAccount' => __('This video belongs to another account.'),
        'lostContact' => __('Lost contact with the site, trying again…'),
        'tooBig' => __('The video is larger than 500 MB.'),
        'expired' => __('The page has expired. Reload it and try again.'),
        'uploadFailed' => __('The upload failed (HTTP :status). Please try again.'),
        'unreachable' => __('The site could not be reached. Check that it is running and try again.'),
    ];
    $originalVolume = (float) old('original_volume', 0);
@endphp
<x-app-layout>
    <section class="hero">
        <div class="wrap hero-inner">
            <h1>{{ __('Give your video a Khmer voice') }}</h1>

            <form id="upload" class="panel upload" method="post" action="{{ route('dub.store') }}" enctype="multipart/form-data">
                @csrf
                <label id="drop" class="drop">
                    <input id="video" name="video" type="file" required
                           accept=".mp4,.mov,.mkv,.webm,video/mp4,video/quicktime,video/x-matroska,video/webm">
                    {{ __('Choose a video') }}
                    <span class="hint">{{ __('Up to 500 MB and 15 minutes') }}</span>
                    <span id="drop-name" class="drop-name"></span>
                </label>

                <fieldset class="field">
                    <legend class="unseen">{{ __('Voice') }}</legend>
                    <div class="voices">
                        <label><input type="radio" name="gender" value="female" @checked(old('gender', 'female') === 'female')>
                            <span><b>{{ __('Sreymom') }}</b> <small>{{ __('Female') }}</small></span></label>
                        <label><input type="radio" name="gender" value="male" @checked(old('gender') === 'male')>
                            <span><b>{{ __('Piseth') }}</b> <small>{{ __('Male') }}</small></span></label>
                    </div>
                </fieldset>

                <label class="toggle">
                    <input type="checkbox" name="dub" value="1" @checked(! $errors->any() || old('dub'))><i></i>
                    <span>{{ __('Dub the video') }}</span>
                </label>
                <div id="original-sound" class="level">
                    <label for="original-volume">{{ __('Original sound') }}</label>
                    <output id="original-level" for="original-volume">{{ $originalVolume > 0 ? round($originalVolume * 100).'%' : __('Off') }}</output>
                    <input id="original-volume" type="range" name="original_volume" min="0" max="1" step="0.05" value="{{ $originalVolume }}">
                </div>
                <label class="toggle">
                    <input type="checkbox" name="review" value="1" @checked(old('review'))><i></i>
                    <span>{{ __('Edit the Khmer first') }}</span>
                </label>

                <button id="start" type="submit">{{ __('Start') }}</button>
                <ul id="errors" class="errors">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </form>
        </div>
    </section>

    <div class="wrap">
        <section id="job" class="panel job" @if (! $current) hidden @endif aria-live="polite">
            <div class="row">
                <h2 id="job-name" class="name">{{ $current['name'] ?? '' }}</h2>
                <span id="job-percent" class="percent">{{ isset($current) ? $current['progress'].'%' : '' }}</span>
            </div>
            <div id="job-bar" class="bar {{ $current['status'] ?? '' }}" role="progressbar" aria-label="{{ $current['message'] ?? '' }}"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $current['progress'] ?? 0 }}">
                <span style="width: {{ $current['progress'] ?? 0 }}%"></span>
            </div>
            <p id="job-message" class="job-message">{{ $current['message'] ?? '' }}</p>
            <p id="job-error" class="error" @if (empty($current['error'])) hidden @endif>{{ $current['error'] ?? '' }}</p>
            <p id="job-language" class="hint" @if (empty($current['language_name'])) hidden @endif><b>{{ $current['language_name'] ?? '' }}</b></p>
            <video id="job-player" class="player" controls playsinline preload="metadata" hidden>
                <track id="job-subtitles" kind="subtitles" srclang="km" label="{{ __('Khmer') }}" default>
            </video>
            <p id="job-player-error" class="error" hidden>{{ __('This browser cannot play the video. Download it instead.') }}</p>
            <div class="links">
                <a id="job-review" class="button sun" href="{{ $current['review_url'] ?? '#' }}" @if (empty($current['review_url'])) hidden @endif>{{ __('Check the Khmer') }}</a>
                <a id="job-video" class="button" href="{{ $current['video_url'] ?? '#' }}" @if (empty($current['video_url'])) hidden @endif>↓ {{ __('Video') }}</a>
                <a id="job-srt" class="button jade" href="{{ $current['srt_url'] ?? '#' }}" @if (empty($current['srt_url'])) hidden @endif>↓ {{ __('Subtitles') }}</a>
            </div>
        </section>

        <section class="videos">
            <h2>{{ __('Your videos') }}</h2>
            <div class="panel">
                <table>
                    <tbody id="history">
                    @forelse ($jobs as $job)
                        <tr data-id="{{ $job->id }}">
                            <td class="name"><a href="{{ route('dub.index', ['job' => $job->id]) }}">{{ $job->original_name }}</a></td>
                            <td class="when">{{ $job->created_at?->diffForHumans() }}</td>
                            <td><span class="pill s-{{ $job->status }}" @if ($job->status === 'done') hidden @endif>{{ $statusLabel[$job->status] ?? $job->status }}</span></td>
                            <td class="results">
                                @if ($job->status === 'review')<a href="{{ route('dub.review', $job) }}">{{ __('Review') }}</a>@endif
                                @if ($job->srt_path)<a href="{{ route('dub.download', [$job, 'srt']) }}">SRT</a>@endif
                                @if ($job->video_path)<a href="{{ route('dub.download', [$job, 'video']) }}">MP4</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr id="history-empty"><td class="empty" colspan="4">{{ __('No videos yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="small-print">
            <p>{{ __('AI translation and voice: check before use. Use only videos you may translate. Files are deleted after 7 days.') }}</p>
        </footer>
    </div>

    <x-slot name="scripts">
    <script>
    (() => {
        const STATUS = @json($statusLabel);
        const TEXT = @json($texts);
        const POLL_MS = 3000;
        const $ = (id) => document.getElementById(id);
        const form = $('upload'), start = $('start'), errors = $('errors'), video = $('video'), drop = $('drop');
        const level = $('original-volume'), dub = form.elements.dub;
        let timer = null;

        function showErrors(list) {
            errors.replaceChildren(...list.map((text) => Object.assign(document.createElement('li'), { textContent: text })));
        }

        /** Show the chosen file in the drop area. */
        function showFile() {
            const file = video.files[0];
            $('drop-name').textContent = file ? file.name : '';
            drop.classList.toggle('chosen', Boolean(file));
        }

        /** Show the original sound level; the slider only applies when there is a voice to play over it. */
        function showLevel() {
            const percent = Math.round(level.value * 100);
            const text = percent ? `${percent}%` : TEXT.off;
            $('original-level').textContent = text;
            level.setAttribute('aria-valuetext', text);
            level.disabled = !dub.checked;
            $('original-sound').hidden = !dub.checked;
        }

        /** Load the job's dubbed video into the player, or empty and hide the player if it has none. */
        function showPlayer(job) {
            const player = $('job-player'), url = job.preview_url || '';
            if ((player.dataset.url || '') === url) return; // polling must not restart a playing video
            player.dataset.url = url;
            player.pause();
            player.hidden = !url;
            $('job-player-error').hidden = true;
            $('job-subtitles').src = job.subtitles_url || '';
            if (url) player.src = url;
            else player.removeAttribute('src');
            player.load();
        }

        /** Draw a job (the JSON from the status route) in the progress card and in its history row. */
        function render(job) {
            $('job').hidden = false;
            $('job-name').textContent = job.name;
            $('job-percent').textContent = `${job.progress}%`;
            const bar = $('job-bar');
            bar.className = `bar ${job.status}`;
            bar.setAttribute('aria-valuenow', job.progress);
            bar.setAttribute('aria-label', job.message || '');
            bar.firstElementChild.style.width = `${job.progress}%`;
            $('job-message').textContent = job.message || '';
            $('job-error').hidden = !job.error;
            $('job-error').textContent = job.error || '';
            $('job-language').hidden = !job.language_name;
            $('job-language').querySelector('b').textContent = job.language_name || '';
            showPlayer(job);
            for (const [id, url] of [['job-review', job.review_url], ['job-srt', job.srt_url], ['job-video', job.video_url]]) {
                $(id).hidden = !url;
                $(id).href = url || '#';
            }
            if (job.id) renderRow(job);
        }

        function renderRow(job) {
            let row = document.querySelector(`#history tr[data-id="${job.id}"]`);
            if (!row) {
                $('history-empty')?.remove();
                row = document.createElement('tr');
                row.dataset.id = job.id;
                row.innerHTML = '<td class="name"><a></a></td><td class="when"></td><td><span class="pill"></span></td><td class="results"></td>';
                row.querySelector('a').textContent = job.name;
                row.querySelector('a').href = `?job=${job.id}`;
                row.querySelector('.when').textContent = TEXT.justNow;
                $('history').prepend(row);
            }
            const pill = row.querySelector('.pill');
            pill.className = `pill s-${job.status}`;
            pill.hidden = job.status === 'done'; // the download links say that already
            pill.textContent = STATUS[job.status] || job.status;
            const links = [[job.review_url, TEXT.review], [job.srt_url, 'SRT'], [job.video_url, 'MP4']].filter(([url]) => url)
                .map(([url, text]) => Object.assign(document.createElement('a'), { href: url, textContent: text }));
            row.querySelector('.results').replaceChildren(...links.flatMap((a) => [a, ' ']));
        }

        /** True once nothing will change until the user acts. */
        const stopped = (status) => ['done', 'failed', 'review'].includes(status);

        /** Ask for the job's status every 3 seconds until it stops. */
        async function poll(url) {
            clearTimeout(timer);
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (response.status === 401 || response.status === 403) {
                    $('job-error').hidden = false;
                    $('job-error').textContent = response.status === 401 ? TEXT.signedOut : TEXT.otherAccount;
                    return;
                }
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const job = await response.json();
                render(job);
                if (stopped(job.status)) return;
            } catch (error) {
                $('job-message').textContent = TEXT.lostContact;
            }
            timer = setTimeout(() => poll(url), POLL_MS);
        }

        video.addEventListener('change', showFile);
        $('job-player').addEventListener('error', (event) => {
            if (!event.target.dataset.url) return; // emptying the player is not a failure
            event.target.hidden = true;
            $('job-player-error').hidden = false;
        });
        level.addEventListener('input', showLevel);
        dub.addEventListener('change', showLevel);
        showLevel();
        for (const name of ['dragenter', 'dragover']) drop.addEventListener(name, () => drop.classList.add('over'));
        for (const name of ['dragleave', 'drop']) drop.addEventListener(name, () => drop.classList.remove('over'));

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const file = video.files[0];
            if (!file) return showErrors([TEXT.chooseFile]);
            showErrors([]);
            clearTimeout(timer);
            start.disabled = true;
            render({ name: file.name, status: 'queued', progress: 0, message: TEXT.uploading, error: null });
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                if (response.status === 201) {
                    const { id, status_url: url } = await response.json();
                    history.replaceState(null, '', `?job=${id}`);
                    form.reset();
                    showFile();
                    showLevel();
                    poll(url);
                    return;
                }
                $('job').hidden = true;
                if (response.status === 422) {
                    const body = await response.json();
                    showErrors(Object.values(body.errors || {}).flat());
                } else if (response.status === 413) {
                    showErrors([TEXT.tooBig]);
                } else if (response.status === 401) {
                    showErrors([TEXT.signedOut]);
                } else if (response.status === 419) {
                    showErrors([TEXT.expired]);
                } else {
                    showErrors([TEXT.uploadFailed.replace(':status', response.status)]);
                }
            } catch (error) {
                $('job').hidden = true;
                showErrors([TEXT.unreachable]);
            } finally {
                start.disabled = false;
            }
        });

        const current = @json($current);
        if (current) {
            render(current);
            if (!stopped(current.status)) poll(current.status_url);
        }
    })();
    </script>
    </x-slot>
</x-app-layout>
