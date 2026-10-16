<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head')</head>
<body class="min-h-dvh bg-slate-50 text-slate-900 antialiased">
<a href="#main-content" class="sr-only focus:not-sr-only">Skip to content</a>
<header class="border-b border-slate-200 bg-white px-5 py-4"><div class="mx-auto flex max-w-2xl items-center justify-between gap-4"><x-brand /><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="min-h-11 text-sm text-slate-600 underline">Sign out</button></form></div></header>
<main id="main-content" class="mx-auto grid max-w-2xl gap-6 px-5 pt-7 pb-32"><x-feedback />{{ $slot }}</main>
<nav aria-label="Rider navigation" class="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)]"><div class="mx-auto grid max-w-2xl grid-cols-4 text-center text-xs">
    <a href="{{ route('rider.home') }}" class="grid min-h-16 place-items-center px-1 font-semibold text-teal-800">Home</a>
    <span aria-disabled="true" class="grid min-h-16 place-items-center px-1 text-slate-400">My Tasks<br>Planned</span>
    <span aria-disabled="true" class="grid min-h-16 place-items-center px-1 text-slate-400">Attendance<br>Planned</span>
    <a href="{{ route('account.settings') }}" class="grid min-h-16 place-items-center px-1 font-semibold text-teal-800">Account</a>
</div></nav>
@livewireScripts
</body>
</html>
