<x-guest-layout>
    <x-slot name="title">Forgot password · Khmer Dub</x-slot>
    <h2>Forgot your password? <span class="km">· ភ្លេចពាក្យសម្ងាត់?</span></h2>
    <p>Enter your email and we will make a link to choose a new password.</p>
    <p class="hint">This tool sends no real email (MAIL_MAILER=log). The link is written to
        <code>web/storage/logs/laravel.log</code>.</p>

    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-field name="email" type="email" label="Email" km="អ៊ីមែល" :value="old('email')" required autofocus />
        <div class="actions">
            <button type="submit">Make the reset link · បង្កើតតំណ</button>
            <a href="{{ route('login') }}">Back to log in · ត្រឡប់ទៅចូល</a>
        </div>
    </form>
</x-guest-layout>
