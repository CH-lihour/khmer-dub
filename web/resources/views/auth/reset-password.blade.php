<x-guest-layout>
    <x-slot name="title">{{ __('New password') }} · Khmer Dub</x-slot>
    <h2>{{ __('Choose a new password') }}</h2>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-field name="email" type="email" :label="__('Email')" :value="old('email', $request->email)" required autofocus autocomplete="username" />
        <x-field name="password" type="password" :label="__('New password')" required autocomplete="new-password" />
        <x-field name="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" />
        <div class="actions">
            <button type="submit">{{ __('Save the new password') }}</button>
        </div>
    </form>
</x-guest-layout>
