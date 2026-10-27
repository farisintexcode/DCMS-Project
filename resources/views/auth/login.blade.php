<x-layouts.guest :card="true" :rider="$portal === 'rider'" :description="$portal === 'rider' ? 'Access your assignments and keep things moving.' : 'Use your staff account to continue securely.'" :title="$portal === 'rider' ? 'Rider sign in' : 'Admin sign in'">
    <form method="POST" action="{{ route($portal === 'rider' ? 'rider.login.store' : 'login.store') }}" class="grid gap-5" x-data="{ submitting: false }" @submit="submitting = true">
        @csrf
        <x-input name="email" label="Email" type="email" :value="old('email')" autocomplete="username" required maxlength="254" autofocus />
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 accent-brand-primary"> Remember me</label>
        <x-button x-bind:disabled="submitting"><span x-show="!submitting">Sign in</span><span x-show="submitting" x-cloak>Signing in…</span></x-button>
    </form>
    <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-dark underline underline-offset-4">Forgot password?</a>
    <p class="text-sm text-ink-500">First time here? Use the activation link provided with your staff invitation.</p>
    <a href="{{ route($portal === 'rider' ? 'login' : 'rider.login') }}" class="text-sm text-ink-600 underline underline-offset-4">{{ $portal === 'rider' ? 'Admin sign in' : 'Rider sign in' }}</a>
</x-layouts.guest>
