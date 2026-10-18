<button type="button" class="theme-toggle grid size-11 shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600 transition-colors hover:bg-brand-soft" x-data="{ dark: document.documentElement.classList.contains('dark') }" @dcms-theme.window="dark = $event.detail" @click="window.dcmsToggleTheme()" :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'" :title="dark ? 'Switch to light mode' : 'Switch to dark mode'" :aria-pressed="dark">
    <span x-show="!dark"><x-icon name="moon" /></span><span x-cloak x-show="dark"><x-icon name="sun" /></span>
</button>
