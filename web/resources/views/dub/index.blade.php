@php
    $statusLabel = ['queued' => __('Queued'), 'processing' => __('Processing'), 'review' => __('Needs review'), 'done' => __('Done'), 'failed' => __('Failed'), 'cancelled' => __('Cancelled')];
    // What the job goes through. `from` is the progress at which a step starts (see the API contract).
    $steps = [
        ['from' => 0, 'label' => __('Sending the video to the worker')],
        ['from' => 5, 'label' => __('Extracting audio')],
        ['from' => 15, 'label' => __('Transcribing speech')],
        ['from' => 45, 'label' => __('Translating to Khmer')],
        ['from' => 60, 'label' => __('Making the Khmer voice'), 'voice' => true],
        ['from' => 85, 'label' => __('Mixing and merging'), 'voice' => true],
        ['from' => 99, 'label' => __('Downloading the results')],
    ];
    $texts = [
        'uploading' => __('Uploading'),
        'lines' => __(':done of :total lines'),
        'review' => __('Review'),
        'cancelLost' => __('The work so far will be lost, and you will need to upload the video again.'),
        'cancelKept' => __('You go back to the review with your edits. Any older video stays.'),
        'delete' => __('Delete'),
        'deleteName' => __('Delete :name'),
        'noVideos' => __('No videos yet'),
        'off' => __('Off'),
        'play' => __('Play'),
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
    $originalVolume = (float) old('original_volume', $options['original_volume']);
    $gender = old('gender', $options['gender']);
    $dubbing = $errors->any() ? old('dub') : $options['dub'];
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

                <x-voice-choice :selected="$gender" />

                <label class="toggle">
                    <input type="checkbox" name="dub" value="1" @checked($dubbing)><i></i>
                    <span>{{ __('Dub the video') }}</span>
                </label>
                <x-original-sound :level="$originalVolume" :off="! $dubbing" />
                <label class="toggle">
                    <input type="checkbox" name="review" value="1" @checked($errors->any() ? old('review') : $options['review'])><i></i>
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
                <button id="job-close" class="close" type="button" aria-label="{{ __('Close the video') }}" title="{{ __('Close the video') }}" hidden>×</button>
            </div>
            <div id="job-bar" class="bar {{ $current['status'] ?? '' }}" role="progressbar" aria-label="{{ $current['message'] ?? '' }}"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $current['progress'] ?? 0 }}">
                <span style="width: {{ $current['progress'] ?? 0 }}%"></span>
            </div>
            <button id="job-toggle" class="now-step" type="button" title="{{ __('Show all steps') }}" aria-expanded="false" aria-controls="job-fold">
                <span id="job-message" class="job-message">{{ $current['message'] ?? '' }}</span>
                <small id="job-count" aria-hidden="true"></small>
            </button>
            <div id="job-fold" class="fold"><ol id="job-steps" class="steps"></ol></div>
            <p id="job-error" class="error" @if (empty($current['error'])) hidden @endif>{{ $current['error'] ?? '' }}</p>
            <p id="job-language" class="hint" @if (empty($current['language_name'])) hidden @endif><b>{{ $current['language_name'] ?? '' }}</b></p>
            <video id="job-player" class="player" controls playsinline preload="metadata" hidden>
                <track id="job-subtitles" kind="subtitles" srclang="km" label="{{ __('Khmer') }}" default>
            </video>
            <p id="job-player-error" class="error" hidden>{{ __('This browser cannot play the video. Download it instead.') }}</p>
            <div class="links">
                <button id="job-cancel" class="quiet" type="button" hidden>{{ __('Cancel') }}</button>
                <a id="job-review" class="button sun" href="{{ $current['review_url'] ?? '#' }}" @if (empty($current['review_url'])) hidden @endif>{{ __('Check the Khmer') }}</a>
                <a id="job-edit" class="button sun" href="{{ $current['edit_url'] ?? '#' }}" @if (empty($current['edit_url'])) hidden @endif>{{ __('Edit and regenerate') }}</a>
                <a id="job-video" class="button" href="{{ $current['video_url'] ?? '#' }}" @if (empty($current['video_url'])) hidden @endif>↓ {{ __('Video') }}</a>
                <a id="job-srt" class="button jade" href="{{ $current['srt_url'] ?? '#' }}" @if (empty($current['srt_url'])) hidden @endif>↓ {{ __('Subtitles') }}</a>
                <button id="job-delete" class="quiet" type="button" hidden>{{ __('Delete') }}</button>
            </div>
        </section>

        <dialog id="cancel-dialog" class="modal" aria-labelledby="cancel-title" aria-describedby="cancel-text">
            <form method="dialog">
                <h2 id="cancel-title">{{ __('Stop making this video?') }}</h2>
                <p id="cancel-text"></p>
                <div class="modal-actions">
                    <button class="quiet" value="keep" autofocus>{{ __('Keep going') }}</button>
                    <button id="cancel-confirm" class="danger" type="button">{{ __('Yes, stop') }}</button>
                </div>
            </form>
        </dialog>

        <dialog id="delete-dialog" class="modal" aria-labelledby="delete-title" aria-describedby="delete-name delete-text">
            <form method="dialog">
                <h2 id="delete-title">{{ __('Delete this video?') }}</h2>
                <p id="delete-name" class="modal-name"></p>
                <p id="delete-text">{{ __('The dubbed video, the subtitles and your edits will be gone for good.') }}</p>
                <p id="delete-error" class="error" hidden></p>
                <div class="modal-actions">
                    <button class="quiet" value="keep" autofocus>{{ __('Keep it') }}</button>
                    <button id="delete-confirm" class="danger" type="button">{{ __('Delete') }}</button>
                </div>
            </form>
        </dialog>

        <section class="videos" id="videos">
            <h2>{{ __('Your videos') }}</h2>
            <div class="panel">
                {{-- #videos: the new page opens at this list, not at the top --}}
                <form class="finder" method="GET" action="{{ route('dub.index') }}#videos" role="search">
                    <label class="sr-only" for="find-q">{{ __('Search by file name') }}</label>
                    <input id="find-q" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="{{ __('Search by file name') }}">
                    <label class="sr-only" for="find-status">{{ __('Status') }}</label>
                    <select id="find-status" name="status">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statusLabel as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit">{{ __('Search') }}</button>
                    @if ($filtered)<a class="clear" href="{{ route('dub.index') }}#videos">{{ __('Clear') }}</a>@endif
                </form>
                <table>
                    <tbody id="history">
                    @forelse ($jobs as $job)
                        <tr data-id="{{ $job->id }}">
                            <td class="name"><a href="{{ route('dub.index', ['job' => $job->id]) }}" data-status-url="{{ route('dub.status', $job) }}">{{ $job->original_name }}</a></td>
                            <td class="when">{{ $job->created_at?->diffForHumans() }}</td>
                            <td><span class="pill s-{{ $job->status }}" @if ($job->status === 'done') hidden @endif>{{ $statusLabel[$job->status] ?? $job->status }}</span></td>
                            <td class="results">
                                @if ($job->hasPlayableVideo())<a class="play" href="{{ route('dub.index', ['job' => $job->id]) }}" data-status-url="{{ route('dub.status', $job) }}">▶ {{ __('Play') }}</a>@endif
                                @if ($job->status === 'review')<a href="{{ route('dub.review', $job) }}">{{ __('Review') }}</a>@endif
                                @if ($job->srt_path)<a href="{{ route('dub.download', [$job, 'srt']) }}">SRT</a>@endif
                                @if ($job->video_path)<a href="{{ route('dub.download', [$job, 'video']) }}">MP4</a>@endif
                                @unless ($job->isRunning())<button class="quiet" type="button" data-delete-url="{{ route('dub.destroy', $job) }}" aria-label="{{ __('Delete :name', ['name' => $job->original_name]) }}">{{ __('Delete') }}</button>@endunless
                            </td>
                        </tr>
                    @empty
                        <tr id="history-empty"><td class="empty" colspan="4">{{ $filtered ? __('No videos match your search') : __('No videos yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
                @if ($jobs->hasPages())
                    <nav class="pager" aria-label="{{ __('Pages') }}">
                        @if ($jobs->onFirstPage())<span aria-disabled="true">← {{ __('Previous') }}</span>@else<a href="{{ $jobs->previousPageUrl() }}#videos" rel="prev">← {{ __('Previous') }}</a>@endif
                        <span class="page-of">{{ __('Page :page of :pages', ['page' => $jobs->currentPage(), 'pages' => $jobs->lastPage()]) }}</span>
                        @if ($jobs->hasMorePages())<a href="{{ $jobs->nextPageUrl() }}#videos" rel="next">{{ __('Next') }} →</a>@else<span aria-disabled="true">{{ __('Next') }} →</span>@endif
                    </nav>
                @endif
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
        const STEPS = @json($steps);
        const TEXT = @json($texts);
        const POLL_MS = 3000;
        const GLIDE_MS = 700;
        // New jobs join the list only on its first page with no search or filter, where they belong.
        const LIVE_LIST = @json($jobs->onFirstPage() && ! $filtered);
        const $ = (id) => document.getElementById(id);
        /** This page's address following job `id` (or none), keeping the search, filter and page. */
        const pageUrl = (id) => {
            const params = new URLSearchParams(location.search);
            id ? params.set('job', id) : params.delete('job');
            return `${location.pathname}${params.size ? `?${params}` : ''}`;
        };
        const form = $('upload'), start = $('start'), errors = $('errors'), video = $('video'), drop = $('drop');
        const level = $('original-volume'), dub = form.elements.dub;
        const SEND_HEADERS = { Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value };
        let timer = null, glide = null, saving = false, changed = false, deleting = null;

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
            level.style.setProperty('--fill', `${percent}%`);
            level.disabled = !dub.checked;
            $('original-sound').classList.toggle('off', !dub.checked);
        }

        /** Load the job's dubbed video into the player, or empty and hide the player if it has none. */
        function showPlayer(job) {
            const player = $('job-player'), url = job.preview_url || '';
            if ((player.dataset.url || '') === url) return; // polling must not restart a playing video
            player.dataset.url = url;
            player.pause();
            player.hidden = !url;
            $('job-close').hidden = !url;
            $('job-player-error').hidden = true;
            $('job-subtitles').src = job.subtitles_url || '';
            if (url) player.src = url;
            else player.removeAttribute('src');
            player.load();
        }

        /** "1:05" for a time in seconds. */
        const clock = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

        /** How far a step is: seconds of the video while transcribing, lines after that. */
        function stepCount(job, step) {
            if (job.step_total == null) return '';
            if (step.from === 15) return `${clock(job.step_done)} / ${clock(job.step_total)}`;
            return TEXT.lines.replace(':done', job.step_done).replace(':total', job.step_total);
        }

        /** Unfold or fold the list of steps under the current one. */
        function foldSteps(open) {
            $('job-toggle').setAttribute('aria-expanded', open);
            $('job-fold').classList.toggle('open', open);
        }

        /** Show what a job is doing now and how far that is, and keep the full list up to date for unfolding. */
        function showSteps(job) {
            const list = $('job-steps'), toggle = $('job-toggle');
            const state = { done: '', failed: 'failed', cancelled: 'cancelled', review: 'waiting' }[job.status] ?? 'now';
            toggle.className = `now-step ${state}`;
            toggle.disabled = job.status === 'done';
            $('job-count').textContent = '';
            if (toggle.disabled) return foldSteps(false);

            const steps = STEPS.filter((step) => job.dub !== false || !step.voice);
            if (list.children.length !== steps.length) {
                list.replaceChildren(...steps.map((step) => Object.assign(document.createElement('li'), { textContent: step.label })));
            }
            const started = steps.findLastIndex((step) => step.from <= job.progress);
            const now = job.status === 'review' ? started + 1 : started; // translated, and waiting for a person
            [...list.children].forEach((item, i) => {
                item.className = i < now ? 'done' : i > now || job.status === 'review' ? '' : state;
            });
            if (job.status === 'processing') $('job-count').textContent = stepCount(job, steps[now]);
        }

        /** Draw a job (the JSON from the status route) in the progress card and in its history row. */
        function render(job) {
            $('job').hidden = false;
            $('job').dataset.id = job.id ?? '';
            $('job-name').textContent = job.name;
            $('job-percent').textContent = `${job.progress}%`;
            const bar = $('job-bar');
            bar.className = `bar ${job.status}`;
            bar.setAttribute('aria-valuenow', job.progress);
            bar.setAttribute('aria-label', job.message || '');
            bar.firstElementChild.style.width = `${job.progress}%`;
            $('job-message').textContent = job.message || '';
            showSteps(job);
            $('job-error').hidden = !job.error;
            $('job-error').textContent = job.error || '';
            $('job-language').hidden = !job.language_name;
            $('job-language').querySelector('b').textContent = job.language_name || '';
            showPlayer(job);
            $('job-cancel').hidden = !job.cancel_url;
            $('job-cancel').disabled = false;
            $('job-cancel').dataset.url = job.cancel_url || '';
            $('job-cancel').dataset.keepsEdits = job.cancel_keeps_edits ? 'yes' : '';
            if (!job.cancel_url && $('cancel-dialog').open) $('cancel-dialog').close(); // it finished or failed meanwhile
            $('job-delete').hidden = !job.delete_url;
            $('job-delete').dataset.url = job.delete_url || '';
            for (const [id, url] of [['job-review', job.review_url], ['job-edit', job.edit_url], ['job-srt', job.srt_url], ['job-video', job.video_url]]) {
                $(id).hidden = !url;
                $(id).href = url || '#';
            }
            if (job.id) renderRow(job);
        }

        function renderRow(job) {
            let row = document.querySelector(`#history tr[data-id="${job.id}"]`);
            if (!row) {
                if (!LIVE_LIST) return;
                $('history-empty')?.remove();
                row = document.createElement('tr');
                row.dataset.id = job.id;
                row.innerHTML = '<td class="name"><a></a></td><td class="when"></td><td><span class="pill"></span></td><td class="results"></td>';
                row.querySelector('a').textContent = job.name;
                row.querySelector('a').href = `?job=${job.id}`;
                row.querySelector('a').dataset.statusUrl = job.status_url;
                row.querySelector('.when').textContent = TEXT.justNow;
                $('history').prepend(row);
            }
            const pill = row.querySelector('.pill');
            pill.className = `pill s-${job.status}`;
            pill.hidden = job.status === 'done'; // the download links say that already
            pill.textContent = STATUS[job.status] || job.status;
            const links = [[job.preview_url && `?job=${job.id}`, `▶ ${TEXT.play}`], [job.review_url, TEXT.review], [job.srt_url, 'SRT'], [job.video_url, 'MP4']]
                .filter(([url]) => url)
                .map(([url, text]) => Object.assign(document.createElement('a'), { href: url, textContent: text }));
            if (job.preview_url) { // the first link, as listed above
                links[0].className = 'play';
                links[0].dataset.statusUrl = job.status_url;
            }
            const results = links.flatMap((a) => [a, ' ']);
            if (job.delete_url) {
                const button = Object.assign(document.createElement('button'), { type: 'button', className: 'quiet', textContent: TEXT.delete });
                button.dataset.deleteUrl = job.delete_url;
                button.setAttribute('aria-label', TEXT.deleteName.replace(':name', job.name));
                results.push(button);
            }
            row.querySelector('.results').replaceChildren(...results);
        }

        /** True once nothing will change until the user acts. */
        const stopped = (status) => ['done', 'failed', 'cancelled', 'review'].includes(status);

        /** Where the page must be scrolled to for `element` to be centred, or to show its top if it is too tall. */
        function centredAt(element) {
            const box = element.getBoundingClientRect();
            return scrollY + box.top - Math.max(0, (innerHeight - box.height) / 2);
        }

        /**
         * Scroll to `element` over GLIDE_MS, measuring it again on every frame because the page can
         * still be changing. The browser's own smooth scroll sets its own speed, which over this
         * distance looks like a jump. Scrolling by hand stops the glide.
         */
        function glideTo(element) {
            cancelGlide();
            if (matchMedia('(prefers-reduced-motion: reduce)').matches) return scrollTo(0, centredAt(element));
            const from = scrollY, started = performance.now();
            const step = (now) => {
                const t = Math.min(Math.max(now - started, 0) / GLIDE_MS, 1);
                const eased = t < .5 ? 4 * t ** 3 : 1 - (2 - 2 * t) ** 3 / 2;
                scrollTo(0, from + (centredAt(element) - from) * eased);
                glide = t < 1 ? requestAnimationFrame(step) : null;
            };
            glide = requestAnimationFrame(step);
        }

        function cancelGlide() {
            cancelAnimationFrame(glide);
            glide = null;
        }

        /** Bring the whole player with its controls into view, or the progress card when there is no video. */
        function showJob() {
            const player = $('job-player');
            const go = () => glideTo(player.hidden ? $('job') : player);
            if (player.hidden || player.readyState >= HTMLMediaElement.HAVE_METADATA) return go();
            const wait = new AbortController(); // the player's height is unknown until then
            for (const name of ['loadedmetadata', 'error']) {
                player.addEventListener(name, () => { wait.abort(); go(); }, { signal: wait.signal });
            }
        }

        /** Stop the video and fold the card away, then do `then`. */
        function hideCard(then) {
            const card = $('job');
            showPlayer({}); // stops the sound and the download
            history.replaceState(null, '', pageUrl(null));
            card.classList.add('closing');
            setTimeout(() => {
                card.classList.remove('closing');
                card.hidden = true;
                then?.();
            }, 200);
        }

        /** Stop and close the video, and go back to its place in the history. */
        function closeJob() {
            const id = new URLSearchParams(location.search).get('job');
            const link = document.querySelector(`#history tr[data-id="${id}"] a`);
            hideCard(() => {
                if (link) {
                    link.focus({ preventScroll: true });
                    glideTo(link.closest('tr'));
                }
            });
        }

        /** Ask whether to stop the job, saying what would happen to the work so far. */
        function askToCancel() {
            const dialog = $('cancel-dialog');
            $('cancel-text').textContent = $('job-cancel').dataset.keepsEdits ? TEXT.cancelKept : TEXT.cancelLost;
            dialog.showModal();
        }

        /** Stop the job. The card says so until it has stopped, which polling then shows. */
        async function cancelJob() {
            const button = $('job-cancel');
            button.disabled = true;
            let problem = TEXT.unreachable;
            try {
                const response = await fetch(button.dataset.url, { method: 'POST', headers: SEND_HEADERS });
                const body = await response.json();
                if (response.ok) {
                    render(body);
                    return $('job-toggle').focus({ preventScroll: true }); // the button that had it is gone
                }
                problem = body.message;
            } catch (error) {
                // the site could not be reached, as `problem` says
            }
            button.disabled = false;
            $('job-error').hidden = false;
            $('job-error').textContent = problem;
        }

        /** Ask whether to delete a job, naming it. */
        function askToDelete(url, id, name) {
            deleting = { url, id };
            $('delete-name').textContent = name;
            $('delete-error').hidden = true;
            $('delete-confirm').disabled = false;
            $('delete-dialog').showModal();
        }

        /** Delete the job. The dialog stays open until that has worked, and shows why if it has not. */
        async function deleteJob() {
            const button = $('delete-confirm');
            button.disabled = true;
            let problem = TEXT.unreachable;
            try {
                const response = await fetch(deleting.url, { method: 'DELETE', headers: SEND_HEADERS });
                if (response.ok) {
                    $('delete-dialog').close();
                    return removeJob(deleting.id);
                }
                problem = (await response.json()).message;
            } catch (error) {
                // the site could not be reached, as `problem` says
            }
            button.disabled = false;
            $('delete-error').hidden = false;
            $('delete-error').textContent = problem;
        }

        /** Take a deleted job off the page: its card, if that is open, and its row in the history. */
        function removeJob(id) {
            const row = document.querySelector(`#history tr[data-id="${id}"]`);
            const next = row?.nextElementSibling ?? row?.previousElementSibling;
            if ($('job').dataset.id === String(id)) {
                clearTimeout(timer);
                hideCard();
            }
            row?.classList.add('closing');
            setTimeout(() => {
                row?.remove();
                next?.querySelector('a')?.focus({ preventScroll: true }); // the button that had it is gone
                if (!$('history').children.length) {
                    const empty = Object.assign(document.createElement('td'), { className: 'empty', colSpan: 4, textContent: TEXT.noVideos });
                    const none = document.createElement('tr');
                    none.id = 'history-empty';
                    none.append(empty);
                    $('history').append(none);
                }
            }, 200);
        }

        /** Choosing a status shows it at once; the search text waits for Enter or the button. */
        $('find-status').addEventListener('change', (event) => event.target.form.requestSubmit());

        /** Open a video from the history without leaving the page. Ctrl or Shift click still opens the link. */
        $('history').addEventListener('click', async (event) => {
            const remove = event.target.closest('button[data-delete-url]');
            if (remove) {
                const row = remove.closest('tr');
                return askToDelete(remove.dataset.deleteUrl, row.dataset.id, row.querySelector('.name a').textContent);
            }
            const link = event.target.closest('a[data-status-url]');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            history.replaceState(null, '', pageUrl(link.closest('tr').dataset.id));
            await poll(link.dataset.statusUrl);
            showJob();
            if (link.classList.contains('play')) $('job-player').play().catch(() => {}); // a browser may refuse; its own button still works
        });

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

        $('job-close').addEventListener('click', closeJob);
        $('job-cancel').addEventListener('click', askToCancel);
        $('cancel-confirm').addEventListener('click', () => {
            $('cancel-dialog').close();
            cancelJob();
        });
        $('job-delete').addEventListener('click', (event) => {
            askToDelete(event.currentTarget.dataset.url, $('job').dataset.id, $('job-name').textContent);
        });
        $('delete-confirm').addEventListener('click', deleteJob);
        for (const dialog of document.querySelectorAll('.modal')) {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close(); // only the backdrop: the form fills the rest
            });
        }
        $('job-toggle').addEventListener('click', (event) => {
            foldSteps(event.currentTarget.getAttribute('aria-expanded') !== 'true');
        });
        video.addEventListener('change', showFile);
        $('job-player').addEventListener('error', (event) => {
            if (!event.target.dataset.url) return; // emptying the player is not a failure
            event.target.hidden = true;
            $('job-player-error').hidden = false;
        });
        for (const name of ['wheel', 'touchstart', 'keydown']) addEventListener(name, cancelGlide, { passive: true });
        level.addEventListener('input', showLevel);
        dub.addEventListener('change', showLevel);
        showLevel();
        for (const name of ['dragenter', 'dragover']) drop.addEventListener(name, () => drop.classList.add('over'));
        for (const name of ['dragleave', 'drop']) drop.addEventListener(name, () => drop.classList.remove('over'));

        /**
         * Save the choices to the account at once, so they survive a refresh. One save at a time,
         * always of the latest choices, so an older save can never finish last and undo a newer one.
         */
        async function saveChoices() {
            changed = true;
            if (saving) return;
            saving = true;
            while (changed) {
                changed = false;
                const choices = new FormData(form);
                choices.delete('video');
                await fetch(@json(route('dub.options')), { method: 'POST', body: choices, headers: { Accept: 'application/json' }, keepalive: true })
                    .catch(() => {}); // the choice still applies to this upload; only remembering it failed
            }
            saving = false;
        }

        form.addEventListener('change', (event) => {
            if (event.target !== video) saveChoices();
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const file = video.files[0];
            if (!file) return showErrors([TEXT.chooseFile]);
            showErrors([]);
            clearTimeout(timer);
            start.disabled = true;
            render({ name: file.name, status: 'queued', progress: 0, message: TEXT.uploading, error: null, dub: dub.checked });
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                if (response.status === 201) {
                    const { id, status_url: url } = await response.json();
                    history.replaceState(null, '', pageUrl(id));
                    video.value = ''; // only the file: the choices stay for the next video
                    showFile();
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
            // Arriving from the edit page or a link. A refresh keeps its place, and so does a plain /dub.
            const arrived = performance.getEntriesByType('navigation')[0]?.type === 'navigate';
            if (arrived && new URLSearchParams(location.search).has('job')) showJob();
        }
    })();
    </script>
    </x-slot>
</x-app-layout>
