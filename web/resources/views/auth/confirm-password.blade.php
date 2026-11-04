<x-guest-layout>
    <x-slot name="title">{{ __('Confirm password') }} · Khmer Dub</x-slot>
    <h2>{{ __('Confirm password') }}</h2>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <x-field name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
        <div class="actions">
            <button type="submit">{{ __('Confirm') }}</button>
        </div>
    </form>
</x-guest-layout>
