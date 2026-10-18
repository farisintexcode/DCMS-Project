<script>
(() => {
    const read = key => { try { return localStorage.getItem(key); } catch { return null; } };
    const write = (key, value) => { try { localStorage.setItem(key, value); } catch {} };
    const system = matchMedia('(prefers-color-scheme: dark)');
    let preference = read('dcms.theme');
    const appearance = () => {
        const dark = ['light', 'dark'].includes(preference) ? preference === 'dark' : system.matches;
        document.documentElement.classList.toggle('dark', dark);
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#171819' : '#ffffff');
        window.dispatchEvent(new CustomEvent('dcms-theme', { detail: dark }));
    };
    window.dcmsToggleTheme = () => {
        preference = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
        write('dcms.theme', preference);
        appearance();
    };
    appearance();
    system.addEventListener('change', appearance);
    window.addEventListener('storage', event => { if (event.key === 'dcms.theme' || event.key === null) { preference = read('dcms.theme'); appearance(); } });
    window.dcmsWorkspace = (portal = 'admin') => ({
        menuOpen: false, compact: portal === 'admin' && read('dcms.sidebar') === 'compact',
        desktop: matchMedia('(min-width: 1024px)').matches, query: '', tooltip: '', tooltipTop: 0, tooltipLeft: 0, touchStart: null,
        get rail() { return portal === 'admin' && this.desktop && this.compact; },
        resize() {
            this.desktop = matchMedia('(min-width: 1024px)').matches;
            if (this.desktop) this.menuOpen = false;
            this.tooltip = '';
        },
        toggleCompact() {
            this.compact = !this.compact;
            write('dcms.sidebar', this.compact ? 'compact' : 'expanded');
            this.tooltip = '';
        },
        openMenu() { this.menuOpen = true; this.$nextTick(() => this.$refs.closeMenu.focus()); },
        closeMenu() { this.menuOpen = false; this.tooltip = ''; this.$nextTick(() => this.$refs.menuToggle?.focus()); },
        searchMenu() {
            this.compact = false;
            write('dcms.sidebar', 'expanded');
            this.$nextTick(() => this.$refs.navigationSearch.focus());
        },
        matches(label) { return !this.query.trim() || label.toLocaleLowerCase().includes(this.query.trim().toLocaleLowerCase()); },
        hint(event, label) {
            if (!this.rail) return;
            const rect = event.currentTarget.getBoundingClientRect();
            this.tooltipTop = Math.max(8, Math.min(innerHeight - 48, rect.top + rect.height / 2 - 18));
            this.tooltipLeft = rect.right + 14;
            this.tooltip = label;
        },
        trap(event) {
            if (this.desktop || !this.menuOpen || event.key !== 'Tab') return;
            const items = [...this.$refs.drawer.querySelectorAll('a[href], button:not(:disabled), input, [tabindex="0"]')].filter(el => el.getClientRects().length);
            const first = items[0], last = items.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        },
        swipe(event) {
            if (!this.desktop && this.touchStart !== null && this.touchStart - event.changedTouches[0].clientX > 60) this.closeMenu();
            this.touchStart = null;
        }
    });
})();
</script>
