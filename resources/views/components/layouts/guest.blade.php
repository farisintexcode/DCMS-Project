@props(['title' => 'Sign in'])
<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head', ['title' => $title])</head>
<body class="min-h-dvh bg-slate-50 text-slate-900 antialiased">
    <main id="main-content" class="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-8 px-5 py-10">
        <x-brand />
        <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>
            <x-feedback />
            {{ $slot }}
        </section>
        <footer class="text-center text-xs text-slate-500">Debt Collector Management System · InteXcode @unless(app()->isProduction()) · {{ app()->environment() }} @endunless</footer>
    </main>
    @livewireScripts
</body>
</html>
