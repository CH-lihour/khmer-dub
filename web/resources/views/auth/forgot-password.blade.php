<x-guest-layout>
    <x-slot name="title">{{ __('Forgot password?') }} · Khmer Dub</x-slot>
    <h2>{{ __('Forgot password?') }}</h2>
    <p class="hint">{{ __('No email is sent. The link goes to') }} <code>web/storage/logs/laravel.log</code></p>

    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-field name="email" type="email" :label="__('Email')" :value="old('email')" required autofocus />
        <div class="actions">
            <button type="submit">{{ __('Make the reset link') }}</button>
            <a href="{{ route('login') }}">{{ __('Log in') }}</a>
        </div>
    </form>
</x-guest-layout>
