<x-guest-layout>
    <x-slot name="title">New password · Khmer Dub</x-slot>
    <h2>Choose a new password <span class="km">· ជ្រើសពាក្យសម្ងាត់ថ្មី</span></h2>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-field name="email" type="email" label="Email" km="អ៊ីមែល" :value="old('email', $request->email)" required autofocus autocomplete="username" />
        <x-field name="password" type="password" label="New password" km="ពាក្យសម្ងាត់ថ្មី" required autocomplete="new-password" />
        <x-field name="password_confirmation" type="password" label="Confirm password" km="បញ្ជាក់ពាក្យសម្ងាត់" required autocomplete="new-password" />
        <div class="actions">
            <button type="submit">Save the new password · រក្សាទុក</button>
        </div>
    </form>
</x-guest-layout>
