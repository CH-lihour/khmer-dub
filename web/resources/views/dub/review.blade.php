@php
    /** "1:05" for a time in seconds. */
    $clock = fn (float $seconds) => sprintf('%d:%02d', floor($seconds / 60), floor($seconds) % 60);
@endphp
<x-app-layout>
    <x-slot name="title">{{ __('Check the Khmer') }} · Khmer Dub</x-slot>
    <div class="wrap page" style="max-width: 58rem; padding-bottom: 0">
        <h1>{{ __('Check the Khmer') }}</h1>
        <p class="lead">{{ __('Empty a line to remove it.') }}</p>
        @if ($job->video_path)
            <p class="hint">{{ __('The current video stays until the new one is ready. You can edit it until its files are deleted, 7 days after upload.') }}</p>
        @endif

        @if ($job->error)
            <p class="errors">{{ \App\Support\Worker::say($job->error) }} {{ __('Your edits are saved. Save again to retry.') }}</p>
        @endif
        @if ($errors->any())
            <ul class="errors">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif

        <form id="review" method="post" action="{{ route('dub.review.save', $job) }}" data-edited="{{ $errors->any() ? 'yes' : '' }}"
              data-dub="{{ $job->dub ? 'yes' : '' }}">
            @csrf
            {{-- The upload, not the dubbed video: the times below are the original speech. --}}
            <div class="panel review-player">
                <video id="player" controls playsinline preload="metadata" src="{{ route('dub.preview', [$job, 'original']) }}"></video>
                <p id="player-error" class="hint" hidden>{{ __('This browser cannot play the uploaded video. Keep it open in another player next to this page.') }}</p>
            </div>
            <ol class="panel lines">
                @foreach ($segments as $i => $seg)
                    <li class="line" data-start="{{ $seg['start'] }}" data-end="{{ $seg['end'] }}" data-next="{{ $segments[$i + 1]['start'] ?? '' }}">
                        <label for="km-{{ $seg['id'] }}">
                            <button class="play-line" type="button" title="{{ __('Play this line') }}">▶ {{ $clock($seg['start']) }} – {{ $clock($seg['end']) }}</button>
                            <span class="original">{{ $seg['text'] }}</span>
                        </label>
                        <div>
                            <textarea id="km-{{ $seg['id'] }}" name="km[{{ $seg['id'] }}]" lang="km" rows="3" maxlength="2000">{{ old('km.'.$seg['id'], $seg['km']) }}</textarea>
                            <p class="fit" aria-live="polite"></p>
                            @error('km.'.$seg['id'])<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </li>
                @endforeach
            </ol>

            @if ($job->dub)
                <div class="panel">
                    <x-voice-choice :selected="old('gender', $job->gender)" />
                    <x-original-sound :level="(float) old('original_volume', $job->original_volume)" />
                </div>
            @endif

            <div class="savebar">
                <button type="submit">{{ $job->video_path ? __('Save and regenerate') : ($job->dub ? __('Save and make the voice') : __('Save the subtitles')) }}</button>
                <a href="{{ route('dub.index', ['job' => $job->id]) }}">{{ __('Back') }}</a>
            </div>
        </form>
    </div>

    <dialog id="empty-dialog" class="modal" aria-labelledby="empty-title" aria-describedby="empty-text">
        <form method="dialog">
            <h2 id="empty-title">{{ __('Remove the empty lines?') }}</h2>
            <p id="empty-text"></p>
            <div class="modal-actions">
                <button class="quiet" value="keep" autofocus>{{ __('Keep editing') }}</button>
                <button id="empty-confirm" class="danger" type="button">{{ __('Remove and save') }}</button>
            </div>
        </form>
    </dialog>

    <x-slot name="scripts">
    <script>
    (() => {
        const $ = (id) => document.getElementById(id);
        const form = $('review'), dialog = $('empty-dialog');
        const EMPTY = { one: @json(__('1 empty line will be removed.')), many: @json(__(':count empty lines will be removed.')) };
        // edited: changes not saved yet (also after a refused save, which gave the typed text back).
        let edited = Boolean(form.dataset.edited), sending = false, confirmed = false;

        form.addEventListener('input', () => { edited = true; });
        form.addEventListener('change', () => { edited = true; });

        /** An empty line is dropped for good, so saving with some asks first. */
        form.addEventListener('submit', (event) => {
            const empty = [...form.querySelectorAll('textarea')].filter((line) => !line.value.trim()).length;
            if (empty && !confirmed) {
                event.preventDefault();
                $('empty-text').textContent = empty === 1 ? EMPTY.one : EMPTY.many.replace(':count', empty);
                return dialog.showModal();
            }
            sending = true;
        });
        $('empty-confirm').addEventListener('click', () => {
            confirmed = true;
            dialog.close();
            form.requestSubmit();
        });
        dialog.addEventListener('close', () => { confirmed = false; });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close(); // only the backdrop: the form fills the rest
        });

        /** Back, a link or closing the tab would lose edits not saved yet, so the browser asks first. */
        addEventListener('beforeunload', (event) => {
            if (!edited || sending) return;
            event.preventDefault();
            event.returnValue = ''; // older browsers ask only with this
        });

        /*
         * The player. ▶ on a line plays that line and stops at its end; while the video plays, the
         * line being said is marked.
         */
        const player = $('player'), lines = [...form.querySelectorAll('.line')];
        let stopAt = null;
        form.addEventListener('click', (event) => {
            const button = event.target.closest('.play-line');
            if (!button) return;
            const line = button.closest('.line');
            player.currentTime = Number(line.dataset.start);
            stopAt = Number(line.dataset.end);
            player.play().catch(() => {}); // a browser may refuse; its own controls still work
        });
        player.addEventListener('timeupdate', () => {
            const now = player.currentTime;
            if (stopAt !== null && now >= stopAt) {
                player.pause();
                stopAt = null;
            }
            for (const line of lines) {
                line.classList.toggle('playing', !player.paused && now >= line.dataset.start && now < line.dataset.end);
            }
        });
        player.addEventListener('seeking', () => { if (player.paused) stopAt = null; }); // moved by hand
        player.addEventListener('pause', () => lines.forEach((line) => line.classList.remove('playing')));
        player.addEventListener('error', () => {
            player.hidden = true;
            $('player-error').hidden = false;
        });

        /*
         * Lines whose Khmer may not fit its time. A voice clip can run on until the next line starts
         * and is sped up at most 1.6 times (MAX_SPEED in worker/stages/mix.py). The length is a guess:
         * edge-tts says about 15 Khmer letters a second, after a short start.
         */
        const FIT = { fast: @json(__('Long for its time: the voice will be sped up.')), cut: @json(__('Too long for its time: the end of the voice may be cut. Shorten it if you can.')) };
        const MAX_SPEED = 1.6;
        const speech = (text) => text.trim() ? .3 + text.replace(/\s/g, '').length / 15 : 0;
        function checkFit(line) {
            const box = line.querySelector('.fit'), start = Number(line.dataset.start);
            const until = Number(line.dataset.next) || player.duration || Number(line.dataset.end) + 1; // the last line has the rest of the video
            const need = speech(line.querySelector('textarea').value), room = until - start;
            const fit = need > room * MAX_SPEED ? 'cut' : need > room ? 'fast' : '';
            box.className = `fit ${fit}`;
            box.textContent = FIT[fit] ?? '';
        }
        if (form.dataset.dub) {
            lines.forEach(checkFit);
            form.addEventListener('input', (event) => {
                const line = event.target.closest('.line');
                if (line) checkFit(line);
            });
            player.addEventListener('loadedmetadata', () => checkFit(lines[lines.length - 1]));
        }

        const level = $('original-volume'), label = $('original-level');
        const off = @json(__('Off'));
        level?.addEventListener('input', () => {
            const percent = Math.round(level.value * 100);
            label.textContent = percent ? `${percent}%` : off;
            level.setAttribute('aria-valuetext', label.textContent);
            level.style.setProperty('--fill', `${percent}%`);
        });
    })();
    </script>
    </x-slot>
</x-app-layout>
