<x-guest-layout>
    <x-slot name="title">{{ __('Register') }} · Khmer Dub</x-slot>
    <h2>{{ __('Create an account') }}</h2>

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <x-field name="name" :label="__('Name')" :value="old('name')" required autofocus autocomplete="name" />
        <x-field name="email" type="email" :label="__('Email')" :value="old('email')" required autocomplete="username" />
        <x-field name="password" type="password" :label="__('Password')" required autocomplete="new-password" />
        <x-field name="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" />
        <div class="actions">
            <button type="submit">{{ __('Register') }}</button>
            <a href="{{ route('login') }}">{{ __('Log in') }}</a>
        </div>
    </form>
</x-guest-layout>
