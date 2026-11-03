<x-app-layout>
    <x-slot name="title">Account · Khmer Dub</x-slot>
    <h1>Account <span class="km">· គណនី</span></h1>
    <p class="lead"><a href="{{ route('dub.index') }}">← Back to your videos · ត្រឡប់ទៅវីដេអូ</a></p>

    <section class="card">
        <h2>Name and email <span class="km">· ឈ្មោះ និងអ៊ីមែល</span></h2>
        @if (session('status') === 'profile-updated')
            <p class="notice">Saved. · បានរក្សាទុក</p>
        @endif
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            @method('patch')
            <x-field name="name" label="Name" km="ឈ្មោះ" :value="old('name', $user->name)" required autocomplete="name" />
            <x-field name="email" type="email" label="Email" km="អ៊ីមែល" :value="old('email', $user->email)" required autocomplete="username" />
            <button type="submit">Save · រក្សាទុក</button>
        </form>
    </section>

    <section class="card">
        <h2>Change password <span class="km">· ប្តូរពាក្យសម្ងាត់</span></h2>
        @if (session('status') === 'password-updated')
            <p class="notice">Password changed. · បានប្តូរពាក្យសម្ងាត់</p>
        @endif
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            @method('put')
            <x-field id="update_password_current_password" name="current_password" type="password" bag="updatePassword"
                     label="Current password" km="ពាក្យសម្ងាត់បច្ចុប្បន្ន" autocomplete="current-password" />
            <x-field id="update_password_password" name="password" type="password" bag="updatePassword"
                     label="New password" km="ពាក្យសម្ងាត់ថ្មី" autocomplete="new-password" />
            <x-field id="update_password_password_confirmation" name="password_confirmation" type="password" bag="updatePassword"
                     label="Confirm new password" km="បញ្ជាក់ពាក្យសម្ងាត់ថ្មី" autocomplete="new-password" />
            <button type="submit">Change password · ប្តូរ</button>
        </form>
    </section>

    <section class="card">
        <details @if ($errors->userDeletion->isNotEmpty()) open @endif>
            <summary>Delete account · លុបគណនី</summary>
            <p>This deletes your account, every video you uploaded and every result, and cannot be undone.
                Download anything you want to keep first.</p>
            <form method="POST" action="{{ route('profile.destroy') }}">
                @csrf
                @method('delete')
                <x-field id="delete_password" name="password" type="password" bag="userDeletion"
                         label="Your password, to confirm" km="ពាក្យសម្ងាត់ ដើម្បីបញ្ជាក់" autocomplete="current-password" />
                <button class="danger" type="submit">Delete my account · លុបគណនីរបស់ខ្ញុំ</button>
            </form>
        </details>
    </section>
</x-app-layout>
