<x-guest-layout>
    <x-slot name="title">{{ __('Log in') }} · Khmer Dub</x-slot>
    <h2>{{ __('Log in') }}</h2>

    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <x-field name="email" type="email" :label="__('Email')" :value="old('email')" required autofocus autocomplete="username" />
        <x-field name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
        <label class="check">
            <input type="checkbox" name="remember">
            <span>{{ __('Remember me') }}</span>
        </label>
        <div class="actions">
            <button type="submit">{{ __('Log in') }}</button>
            <a href="{{ route('register') }}">{{ __('Register') }}</a>
        </div>
    </form>

    <p class="switch"><a href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a></p>
</x-guest-layout>
