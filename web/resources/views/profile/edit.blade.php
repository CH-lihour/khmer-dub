<x-app-layout>
    <x-slot name="title">{{ __('Account') }} · Khmer Dub</x-slot>
    <div class="wrap page">
        <h1 class="page-title">{{ __('Account') }}</h1>

        <section class="panel">
            <h2>{{ __('Name and email') }}</h2>
            @if (session('status') === 'profile-updated')
                <p class="notice">{{ __('Saved') }}</p>
            @endif
            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('patch')
                <x-field name="name" :label="__('Name')" :value="old('name', $user->name)" required autocomplete="name" />
                <x-field name="email" type="email" :label="__('Email')" :value="old('email', $user->email)" required autocomplete="username" />
                <button type="submit">{{ __('Save') }}</button>
            </form>
        </section>

        <section class="panel">
            <h2>{{ __('Change password') }}</h2>
            @if (session('status') === 'password-updated')
                <p class="notice">{{ __('Password changed') }}</p>
            @endif
            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                @method('put')
                <x-field id="update_password_current_password" name="current_password" type="password" bag="updatePassword"
                         :label="__('Current password')" autocomplete="current-password" />
                <x-field id="update_password_password" name="password" type="password" bag="updatePassword"
                         :label="__('New password')" autocomplete="new-password" />
                <x-field id="update_password_password_confirmation" name="password_confirmation" type="password" bag="updatePassword"
                         :label="__('Confirm new password')" autocomplete="new-password" />
                <button type="submit">{{ __('Change password') }}</button>
            </form>
        </section>

        <section class="panel">
            <details @if ($errors->userDeletion->isNotEmpty()) open @endif>
                <summary>{{ __('Delete account') }}</summary>
                <p>{{ __('This deletes your account and all your videos for good.') }}</p>
                <form method="POST" action="{{ route('profile.destroy') }}">
                    @csrf
                    @method('delete')
                    <x-field id="delete_password" name="password" type="password" bag="userDeletion"
                             :label="__('Your password, to confirm')" autocomplete="current-password" />
                    <button class="danger" type="submit">{{ __('Delete my account') }}</button>
                </form>
            </details>
        </section>
    </div>
</x-app-layout>
