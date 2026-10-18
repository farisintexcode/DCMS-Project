<x-layouts.guest title="Recover your account">
    <p class="text-sm text-ink-600">Enter your registered staff email to request a password recovery link.</p>
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-5">
        @csrf
        <x-input name="email" label="Email" type="email" :value="old('email')" required autocomplete="username" />
        <x-button>Request password change</x-button>
    </form>
    <a href="{{ route('login') }}" class="text-sm text-brand-dark underline">Back to sign in</a>
</x-layouts.guest>
