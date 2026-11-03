<x-guest-layout>
    <x-slot name="title">Register · Khmer Dub</x-slot>
    <h2>Create an account <span class="km">· បង្កើតគណនី</span></h2>

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <x-field name="name" label="Name" km="ឈ្មោះ" :value="old('name')" required autofocus autocomplete="name" />
        <x-field name="email" type="email" label="Email" km="អ៊ីមែល" :value="old('email')" required autocomplete="username" />
        <x-field name="password" type="password" label="Password" km="ពាក្យសម្ងាត់" required autocomplete="new-password" />
        <x-field name="password_confirmation" type="password" label="Confirm password" km="បញ្ជាក់ពាក្យសម្ងាត់" required autocomplete="new-password" />
        <div class="actions">
            <button type="submit">Register · ចុះឈ្មោះ</button>
        </div>
    </form>

    <p class="switch">Already registered? · មានគណនីរួចហើយ? <a href="{{ route('login') }}">Log in · ចូល</a></p>
</x-guest-layout>
