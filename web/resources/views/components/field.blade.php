{{-- A labelled input with its validation errors. Extra attributes (required, autocomplete...) go on the input. --}}
@props(['name', 'label', 'km' => null, 'type' => 'text', 'value' => null, 'bag' => 'default', 'id' => null])
@php($id ??= $name)
<div class="field">
    <label for="{{ $id }}">{{ $label }}@if ($km) <span class="km">· {{ $km }}</span>@endif</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($type !== 'password') value="{{ $value }}" @endif {{ $attributes }}>
    @foreach ($errors->getBag($bag)->get($name) as $message)
        <p class="field-error">{{ $message }}</p>
    @endforeach
</div>
