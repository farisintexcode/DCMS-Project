{{-- M01 / UI05: own-account navigation; logout uses the existing CSRF-protected action. --}}
<div class="relative" x-data="{
    accountOpen: false,
    closeAccount(restore = false) { this.accountOpen = false; if (restore) this.$refs.accountTrigger.focus(); },
    focusAccount(index) {
        this.accountOpen = true;
        this.$nextTick(() => {
            const items = [...this.$refs.accountPanel.querySelectorAll('[role=menuitem]')];
            items[(index + items.length) % items.length].focus();
        });
    },
    moveAccount(event) {
        const items = [...this.$refs.accountPanel.querySelectorAll('[role=menuitem]')];
        const current = items.indexOf(document.activeElement);
        if (event.key === 'ArrowDown') { event.preventDefault(); this.focusAccount(current + 1); }
        if (event.key === 'ArrowUp') { event.preventDefault(); this.focusAccount(current - 1); }
        if (event.key === 'Home') { event.preventDefault(); this.focusAccount(0); }
        if (event.key === 'End') { event.preventDefault(); this.focusAccount(items.length - 1); }
    }
}" @click.outside="closeAccount()" @keydown.escape.stop.prevent="closeAccount(true)" @focusout="if (!$el.contains($event.relatedTarget)) closeAccount()">
    <button type="button" x-ref="accountTrigger" id="rider-account-trigger" aria-label="Profile" aria-haspopup="menu" :aria-expanded="accountOpen" aria-controls="rider-account-menu" class="rider-avatar transition-transform active:scale-95" @click="accountOpen ? closeAccount() : focusAccount(0)" @keydown.arrow-down.prevent="focusAccount(0)" @keydown.arrow-up.prevent="focusAccount(2)"><x-profile-photo /></button>
    <div x-ref="accountPanel" x-cloak x-show="accountOpen" x-transition.opacity.duration.150ms id="rider-account-menu" class="rider-account-menu" @keydown="moveAccount($event)">
        <div class="flex items-center gap-3 border-b border-ink-200 p-4"><span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-full border-2 border-white bg-brand-soft font-semibold"><x-profile-photo /></span><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p><p class="mt-1 break-all text-xs text-ink-500">{{ auth()->user()->email }}</p></div></div>
        <div role="menu" aria-labelledby="rider-account-trigger" class="p-2">
            <a role="menuitem" tabindex="-1" href="{{ route('rider.profile') }}" @if(request()->routeIs('rider.profile')) aria-current="page" @endif @click="closeAccount()" class="rider-account-item"><x-icon name="user" class="size-4" />My Profile</a>
            <a role="menuitem" tabindex="-1" href="{{ route('account.settings') }}" @if(request()->routeIs('account.settings')) aria-current="page" @endif @click="closeAccount()" class="rider-account-item"><x-icon name="settings" class="size-4" />Account Settings</a>
            <div class="my-2 border-t border-ink-200" role="separator"></div>
            <form method="POST" action="{{ route('logout') }}" @submit="closeAccount()">@csrf<button role="menuitem" tabindex="-1" type="submit" class="rider-account-item rider-account-logout"><x-icon name="logout" class="size-4" />Logout</button></form>
        </div>
    </div>
</div>
