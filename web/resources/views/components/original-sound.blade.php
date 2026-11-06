@props(['level', 'off' => false])
<div id="original-sound" @class(['level', 'off' => $off])>
    <label for="original-volume">{{ __('Original sound') }}</label>
    <output id="original-level" for="original-volume">{{ $level > 0 ? round($level * 100).'%' : __('Off') }}</output>
    <input id="original-volume" type="range" name="original_volume" min="0" max="1" step="0.05" value="{{ $level }}" style="--fill: {{ round($level * 100) }}%">
</div>
