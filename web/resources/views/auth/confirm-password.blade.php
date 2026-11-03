<x-guest-layout>
    <x-slot name="title">Confirm password · Khmer Dub</x-slot>
    <h2>Confirm your password <span class="km">· បញ្ជាក់ពាក្យសម្ងាត់</span></h2>
    <p>This part of the site needs your password again before you continue.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <x-field name="password" type="password" label="Password" km="ពាក្យសម្ងាត់" required autocomplete="current-password" />
        <div class="actions">
            <button type="submit">Confirm · បញ្ជាក់</button>
        </div>
    </form>
</x-guest-layout>
