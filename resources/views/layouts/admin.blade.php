<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head')</head>
<body class="min-h-dvh bg-slate-50 text-slate-900 antialiased" x-data="{ menuOpen: false }">
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-4">Skip to content</a>
<header class="fixed inset-x-0 top-0 z-30 flex h-20 items-center justify-between border-b border-slate-200 bg-white px-5 lg:px-8">
    <div class="flex items-center gap-4"><button type="button" class="min-h-11 rounded-lg border border-slate-200 px-3 lg:hidden" @click="menuOpen = !menuOpen" x-bind:aria-expanded="menuOpen" aria-controls="admin-navigation">Menu</button><x-brand /></div>
    <div class="flex items-center gap-4 text-sm"><span class="hidden text-xs text-slate-500 sm:inline">@unless(app()->isProduction()){{ app()->environment() }} · @endunless Admin</span><a href="{{ route('account.settings') }}" class="min-h-11 content-center font-medium">Account</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="min-h-11 text-slate-600 underline" type="submit">Sign out</button></form></div>
</header>
<aside id="admin-navigation" class="fixed inset-y-0 left-0 z-20 w-64 overflow-y-auto border-r border-slate-200 bg-white px-5 pt-28 pb-8 transition-transform lg:translate-x-0" x-bind:class="menuOpen ? 'translate-x-0' : '-translate-x-full'" @keydown.escape.window="menuOpen = false">
    <nav aria-label="Admin navigation" class="grid gap-2 text-sm">
        <a href="{{ route('admin.home') }}" @class(['rounded-lg px-4 py-3 font-semibold text-teal-900', 'bg-teal-50' => request()->routeIs('admin.home')]) @if(request()->routeIs('admin.home')) aria-current="page" @endif>Home</a>
        @can('customers.view')<a href="{{ route('admin.customers.index') }}" @class(['rounded-lg px-4 py-3 font-semibold text-teal-900', 'bg-teal-50' => request()->routeIs('admin.customers.*')]) @if(request()->routeIs('admin.customers.*')) aria-current="page" @endif>Customers</a>@endcan
        <p class="px-4 pt-6 pb-2 text-xs font-semibold tracking-wider text-slate-400 uppercase">Upcoming modules</p>
        @foreach (['riders.manage' => 'Riders & staff', 'assignments.view' => 'Assignments', 'imports.view' => 'Excel imports', 'tasks.monitor' => 'Tasks', 'tasks.verify' => 'Verification', 'attendance.view_all' => 'Attendance', 'calls.record' => 'Call Centre', 'communications.view' => 'Communications', 'reports.view' => 'Reports', 'roles.manage' => 'Administration'] as $permission => $label)
            @can($permission)<span aria-disabled="true" class="flex justify-between gap-2 rounded-lg px-4 py-3 text-slate-500">{{ $label }} <span class="text-xs">Planned</span></span>@endcan
        @endforeach
    </nav>
    <p class="px-4 pt-8 text-xs leading-5 text-slate-400">DCMS · InteXcode</p>
</aside>
<main id="main-content" class="px-5 pt-28 pb-12 lg:ml-64 lg:px-10">
    <div class="mx-auto grid max-w-6xl gap-6"><p class="text-xs text-slate-500">Home / {{ request()->routeIs('account.settings') ? 'Account' : (request()->routeIs('admin.customers.*') ? 'Customers' : 'Overview') }}</p><x-feedback />{{ $slot }}</div>
</main>
@livewireScripts
</body>
</html>
