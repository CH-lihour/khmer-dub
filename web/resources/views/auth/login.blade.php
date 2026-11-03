<x-guest-layout>
    <x-slot name="title">Log in · Khmer Dub</x-slot>
    <h2>Log in <span class="km">· ចូលគណនី</span></h2>

    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <x-field name="email" type="email" label="Email" km="អ៊ីមែល" :value="old('email')" required autofocus autocomplete="username" />
        <x-field name="password" type="password" label="Password" km="ពាក្យសម្ងាត់" required autocomplete="current-password" />
        <label class="check">
            <input type="checkbox" name="remember">
            <span>Remember me <span class="km">· ចងចាំខ្ញុំ</span></span>
        </label>
        <div class="actions">
            <button type="submit">Log in · ចូល</button>
            <a href="{{ route('password.request') }}">Forgot your password? · ភ្លេចពាក្យសម្ងាត់?</a>
        </div>
    </form>

    <p class="switch">No account yet? · មិនទាន់មានគណនី? <a href="{{ route('register') }}">Register · ចុះឈ្មោះ</a></p>
</x-guest-layout>
