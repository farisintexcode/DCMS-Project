<section class="grid max-w-xl gap-6">
    <div><h1 class="text-2xl font-semibold">Account settings</h1><p class="mt-2 text-sm text-slate-600">{{ auth()->user()->name }} · {{ auth()->user()->email }}</p></div>
    <form wire:submit="save" class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
        <x-input name="current_password" label="Current password" type="password" wire:model="current_password" required autocomplete="current-password" />
        <x-input name="password" label="New password" type="password" wire:model="password" required minlength="12" autocomplete="new-password" />
        <p class="text-xs text-slate-500">At least 12 characters, mixed case letters, a number and a symbol.</p>
        <x-input name="password_confirmation" label="Confirm new password" type="password" wire:model="password_confirmation" required autocomplete="new-password" />
        <x-button wire:loading.attr="disabled" wire:target="save">Update password</x-button>
    </form>
</section>
