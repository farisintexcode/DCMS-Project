@props(['portal' => 'admin'])
@php($archivedAssignment = request()->attributes->get('assignment_status', request()->query('status', 'active')) === 'archived')
<button type="button" x-cloak x-show="menuOpen && !desktop" @click="closeMenu()" class="fixed inset-0 z-30 bg-black/25 backdrop-blur-[2px] lg:hidden" aria-label="Close navigation"></button>
<aside x-ref="drawer" id="{{ $portal }}-navigation" class="dcms-sidebar" inert :class="{ 'is-open': menuOpen }" :inert="!desktop && !menuOpen" :role="desktop ? 'complementary' : 'dialog'" :aria-modal="!desktop && menuOpen ? 'true' : null" aria-label="{{ ucfirst($portal) }} sidebar" @touchstart.passive="touchStart = $event.touches[0].clientX" @touchend.passive="swipe($event)">
    <div class="sidebar-brand"><a href="{{ route($portal.'.home') }}" aria-label="DebtOps home" class="flex min-h-12 items-center gap-3"><x-brand /></a><button type="button" class="sidebar-mobile-close grid size-11 shrink-0 place-items-center rounded-xl border border-ink-200 lg:hidden" x-ref="closeMenu" aria-label="Close menu" @click="closeMenu()"><x-icon name="close" /></button></div>
    <div class="sidebar-search"><button type="button" class="sidebar-search-icon sidebar-link" aria-label="Search navigation" @click="searchMenu()" @mouseenter="hint($event, 'Search navigation')" @mouseleave="tooltip = ''" @focus="hint($event, 'Search navigation')" @blur="tooltip = ''"><x-icon name="search" /></button><label class="sidebar-search-field"><x-icon name="search" class="size-4 text-ink-500" /><span class="sr-only">Search navigation</span><input x-ref="navigationSearch" type="search" placeholder="Find a page…" x-model="query" maxlength="100" autocomplete="off" @keydown.escape.stop="if (query) query = ''; else if (!desktop) closeMenu()"></label></div>
    <div class="sidebar-scroll"><nav x-ref="navigationItems" aria-label="{{ $portal === 'rider' ? 'Rider menu' : 'Admin navigation' }}" class="grid gap-1.5">
    @if($portal === 'admin')
        @can('attendance.view_own')<x-sidebar-item label="My attendance" icon="clock" :href="route('attendance.own')" :active="request()->routeIs('attendance.own')" />@endcan
        <x-sidebar-item label="Dashboard" icon="grid" :href="route('admin.home')" :active="request()->routeIs('admin.home')" />
        @can('assignments.view')<x-sidebar-group label="Assignment batches" icon="briefcase" :active="request()->routeIs('admin.assignments.*')" keywords="Active assignments Archived assignments"><x-sidebar-item label="Active assignments" icon="briefcase" parent="Assignment batches" :href="route('admin.assignments.index')" :active="request()->routeIs('admin.assignments.*') && !$archivedAssignment" /><x-sidebar-item label="Archived assignments" icon="clock" parent="Assignment batches" :href="route('admin.assignments.index', ['status'=>'archived'])" :active="request()->routeIs('admin.assignments.*') && $archivedAssignment" /></x-sidebar-group>@endcan
        @canany(['customers.view', 'customers.manage_categories'])
        <x-sidebar-group label="Customer management" icon="users" :active="request()->routeIs('admin.customers.*')" keywords="Customers Customer categories">
            @can('customers.view')<x-sidebar-item label="Customers" icon="users" parent="Customer management" :href="route('admin.customers.index')" :active="request()->routeIs('admin.customers.*') && !request()->routeIs('admin.customers.categories')" />@endcan
            @can('customers.manage_categories')<x-sidebar-item label="Customer categories" icon="tag" parent="Customer management" :href="route('admin.customers.categories')" :active="request()->routeIs('admin.customers.categories')" />@endcan
        </x-sidebar-group>
        @endcanany
        @can('riders.manage')<x-sidebar-item label="Riders & staff" icon="user" :href="route('admin.staff.index')" :active="request()->routeIs('admin.staff.*')" />@endcan
        @can('imports.view')<x-sidebar-item label="Excel imports" icon="upload" :href="route('admin.imports.index')" :active="request()->routeIs('admin.imports.*')" />@endcan
        @can('tasks.monitor')<x-sidebar-item label="Task monitoring" icon="clipboard" :href="route('admin.tasks.index')" :active="request()->routeIs('admin.tasks.*')" />@endcan
        @can('tasks.verify')<x-sidebar-item label="Verification" icon="shield" :href="route('admin.verification.index')" :active="request()->routeIs('admin.verification.*')" />@endcan
        @can('attendance.view_all')<x-sidebar-item label="Attendance" icon="clock" :href="route('admin.attendance.index')" :active="request()->routeIs('admin.attendance.*')" />@endcan
        @can('viewAny',\App\Models\CollectionActivity::class)<x-sidebar-item label="Call Centre" icon="phone" :href="route('admin.calls.index')" :active="request()->routeIs('admin.calls.*')" />@endcan
        <div class="sidebar-divider" x-show="!query.trim()"></div>
        <p class="sidebar-section sidebar-label" x-show="!query.trim()">Upcoming modules</p>
        @can('viewAny',\App\Models\CommunicationTemplate::class)
<x-sidebar-item label="Communications" icon="mail" :href="route('admin.communications.index')" :active="request()->routeIs('admin.communications.*')" />
@endcan
@foreach(['reports.view'=>['Reports','chart'],'roles.manage'=>['Administration','settings']] as $permission=>$item)
            @can($permission)<x-sidebar-item :label="$item[0]" :icon="$item[1]" planned />@endcan
        @endforeach
    @else
        <x-sidebar-item label="Home" icon="home" :href="route('rider.home')" :active="request()->routeIs('rider.home')" />
        @can('tasks.view')<x-sidebar-item label="My Tasks" icon="briefcase" :href="route('rider.tasks.index')" :active="request()->routeIs('rider.tasks.*')" />@endcan
        @can('attendance.view_own')<x-sidebar-item label="Attendance" icon="clock" :href="route('rider.attendance')" :active="request()->routeIs('rider.attendance')" />@endcan
    @endif
    </nav><p x-cloak x-show="query.trim() && ![...$refs.navigationItems.querySelectorAll('[data-nav-search]')].some(el => matches(el.dataset.navSearch))" class="sidebar-label px-3 py-6 text-xs leading-6 text-ink-500" role="status">No matching pages. Try another menu name.</p></div>
    <div class="sidebar-footer"><span class="sidebar-label text-[10px] leading-5 text-ink-500">DebtOps · InteXcode<br>Debt Collection Management System</span>@if($portal === 'admin')<button type="button" class="sidebar-collapse grid size-11 shrink-0 place-items-center rounded-xl border border-ink-200 hover:bg-brand-soft" :aria-label="rail ? 'Expand sidebar' : 'Collapse sidebar'" :aria-expanded="!rail" aria-controls="admin-navigation" @click="toggleCompact()"><x-icon name="panel" /></button>@endif</div>
</aside>
<x-sidebar-tooltip />

