@props(['selected'])
@php
    $voices = [['female', __('Sreymom'), __('Female')], ['male', __('Piseth'), __('Male')]];
@endphp
<fieldset class="field">
    <legend class="unseen">{{ __('Voice') }}</legend>
    <div class="voices">
        @foreach ($voices as [$value, $name, $kind])
            <label><input type="radio" name="gender" value="{{ $value }}" @checked($selected === $value)>
                <span><b>{{ $name }}</b> <small>{{ $kind }}</small></span>
                {{-- public/voices: one sentence from each edge-tts voice, made as the README says --}}
                <button class="listen" type="button" data-sample="{{ asset("voices/{$value}.mp3") }}"
                        aria-label="{{ __('Listen to :name', ['name' => $name]) }}" title="{{ __('Listen to :name', ['name' => $name]) }}">▶</button>
            </label>
        @endforeach
    </div>
</fieldset>
@once
<script>
(() => {
    /** Play a voice's sample; the same button stops it. One sample plays at a time. */
    const sample = new Audio();
    let playing = null;
    const stop = () => {
        sample.pause();
        playing?.classList.remove('playing');
        playing?.replaceChildren('▶');
        playing = null;
    };
    sample.addEventListener('ended', stop);
    sample.addEventListener('error', stop);
    document.addEventListener('click', (event) => {
        const button = event.target.closest('button.listen');
        if (!button) return;
        event.preventDefault(); // a button inside the label would also pick that voice
        const again = button === playing;
        stop();
        if (again) return;
        playing = button;
        button.classList.add('playing');
        button.replaceChildren('■');
        sample.src = button.dataset.sample;
        sample.play().catch(stop);
    });
})();
</script>
@endonce
