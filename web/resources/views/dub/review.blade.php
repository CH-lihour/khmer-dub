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
            <p class="errors">{{ __($job->error) }} {{ __('Your edits are saved. Save again to retry.') }}</p>
        @endif
        @if ($errors->any())
            <ul class="errors">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif

        <form method="post" action="{{ route('dub.review.save', $job) }}">
            @csrf
            <ol class="panel lines">
                @foreach ($segments as $seg)
                    <li class="line">
                        <label for="km-{{ $seg['id'] }}">
                            <span class="hint">{{ $clock($seg['start']) }} – {{ $clock($seg['end']) }}</span>
                            <span class="original">{{ $seg['text'] }}</span>
                        </label>
                        <div>
                            <textarea id="km-{{ $seg['id'] }}" name="km[{{ $seg['id'] }}]" lang="km" rows="3" maxlength="2000">{{ old('km.'.$seg['id'], $seg['km']) }}</textarea>
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

    @if ($job->dub)
        <x-slot name="scripts">
        <script>
        (() => {
            const level = document.getElementById('original-volume'), label = document.getElementById('original-level');
            const off = @json(__('Off'));
            level.addEventListener('input', () => {
                const percent = Math.round(level.value * 100);
                label.textContent = percent ? `${percent}%` : off;
                level.setAttribute('aria-valuetext', label.textContent);
                level.style.setProperty('--fill', `${percent}%`);
            });
        })();
        </script>
        </x-slot>
    @endif
</x-app-layout>
