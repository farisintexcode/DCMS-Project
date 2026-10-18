<x-layouts.guest :title="$activation ? 'Activate your account' : 'Set a new password'">
    <form method="POST" action="{{ route($activation ? 'activation.update' : 'password.update') }}" class="grid gap-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" label="Registered email" type="email" :value="old('email', $email)" required autocomplete="username" />
        @if ($activation)<x-input name="name" label="Your name" :value="old('name')" required maxlength="160" autocomplete="name" />@endif
        <x-input name="password" label="New password" type="password" required minlength="12" autocomplete="new-password" />
        <p class="text-xs text-ink-500">Use at least 12 characters, with upper and lower case letters, a number and a symbol.</p>
        <x-input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        @if ($activation)
            @if (config('dcms.privacy_url') && config('dcms.terms_version'))
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="terms" value="1" required class="mt-1 size-4 shrink-0 accent-brand-primary"> <span>I accept the terms and <a href="{{ config('dcms.privacy_url') }}" class="text-brand-dark underline">privacy notice</a> ({{ config('dcms.terms_version') }}).</span></label>
            @else
                <p class="text-sm text-amber-800">Account activation is awaiting the approved terms and privacy notice.</p>
            @endif
        @endif
        <x-button :disabled="$activation && (!config('dcms.privacy_url') || !config('dcms.terms_version'))">{{ $activation ? 'Activate account' : 'Save password' }}</x-button>
    </form>
    <a href="{{ route('login') }}" class="text-sm text-brand-dark underline">Back to sign in</a>
</x-layouts.guest>
