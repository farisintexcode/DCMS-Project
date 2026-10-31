<section class="rider-page rider-account-settings-page">
    <x-feedback />
    <x-page-header title="Account Settings" eyebrow="" description="Manage your security and choose how your workspace looks." />
    <form wire:submit="save" class="surface rider-account-card grid gap-5" aria-labelledby="rider-security-title">
        <div class="flex items-center gap-3 border-b border-ink-100 pb-5"><span class="icon-tile"><x-icon name="shield" /></span><div><h2 id="rider-security-title" class="text-base font-semibold">Change password</h2><p class="mt-1 text-xs leading-5 text-ink-500">Keep your account secure with a strong, unique password.</p></div></div>
        <div class="grid gap-5 sm:grid-cols-2"><div class="sm:col-span-2"><x-input name="current_password" label="Current Password" type="password" wire:model="current_password" required autocomplete="current-password" /></div><x-input name="password" label="New Password" type="password" wire:model="password" required minlength="12" autocomplete="new-password" /><x-input name="password_confirmation" label="Confirm New Password" type="password" wire:model="password_confirmation" required autocomplete="new-password" /></div>
        <div class="rounded-xl bg-brand-soft p-4"><h3 class="text-xs font-semibold">Password requirements</h3><p class="mt-2 text-xs leading-6 text-ink-600">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol. Choose a password different from your current one.</p></div>
        <div class="flex flex-wrap items-center gap-4 border-t border-ink-100 pt-5"><x-button wire:loading.attr="disabled" wire:target="save"><x-icon name="lock" class="size-4" />Update Password</x-button><span wire:loading wire:target="save" class="text-xs text-ink-500" role="status">Updating your password…</span></div>
    </form>
    <section class="surface rider-account-card" aria-labelledby="rider-appearance-title" x-data="{ preference: window.dcmsThemePreference() }" @dcms-appearance.window="preference = $event.detail" @storage.window="preference = window.dcmsThemePreference()">
        <div class="flex items-center gap-3"><span class="icon-tile"><x-icon name="sun" /></span><div><h2 id="rider-appearance-title" class="text-base font-semibold">Appearance</h2><p class="mt-1 text-xs leading-5 text-ink-500">Choose a comfortable view for your workspace.</p></div></div>
        <fieldset class="mt-6 grid gap-3 sm:grid-cols-3"><legend class="sr-only">Preferred appearance</legend>
            @foreach(['light' => ['Light Mode', 'sun', 'Bright and clear'], 'dark' => ['Dark Mode', 'moon', 'Easy on the eyes'], 'system' => ['System Default', 'settings', 'Follow your device']] as $value => [$label, $icon, $description])
                <label class="rider-appearance-option" :class="{ 'is-selected': preference === '{{ $value }}' }"><input type="radio" name="appearance" value="{{ $value }}" x-model="preference" @change="window.dcmsSetTheme($event.target.value)" class="size-4 shrink-0 accent-brand-accent" /><span class="min-w-0 flex-1"><span class="flex items-center gap-2 text-sm font-semibold"><x-icon :name="$icon" class="size-4" />{{ $label }}</span><span class="mt-1 block text-xs leading-5 text-ink-500">{{ $description }}</span></span></label>
            @endforeach
        </fieldset>
        <p class="mt-4 text-xs leading-6 text-ink-500">Your preference is saved in this browser and applies across your workspace.</p>
    </section>
</section>
