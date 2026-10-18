@props(['title' => 'Sign in', 'description' => 'Use your staff account to continue securely.'])
<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head', ['title' => $title])</head>
<body class="bg-white text-brand-dark antialiased"><div class="absolute top-4 right-4 z-10"><x-theme-toggle /></div>
<main id="main-content" class="grid min-h-dvh lg:grid-cols-2">
    <section class="auth-art relative hidden flex-col justify-between overflow-hidden border-r border-ink-200 p-12 lg:flex xl:p-16">
        <x-brand />
        <div class="relative z-10 max-w-lg py-12"><p class="eyebrow">The DebtOps collection workspace</p><h2 class="mt-6 text-5xl leading-[1.1] font-semibold tracking-[-0.055em] xl:text-6xl">Clarity for every<br><span class="relative inline-block">collection.<span class="absolute -bottom-2 left-0 h-1.5 w-24 rounded-full bg-brand-primary"></span></span></h2><p class="mt-8 max-w-sm text-base leading-7 text-ink-600">A focused workspace for customer records, field teams and the work that connects them.</p>
        <div class="auth-grid mt-12 rounded-3xl border border-ink-200 bg-white/70 p-8"><div class="surface flex items-center gap-4 p-4"><span class="icon-tile"><x-icon name="users" /></span><div><p class="text-sm font-semibold">Your customers. In focus.</p><p class="mt-1 text-xs text-ink-500">Organized records. Clear context.</p></div><x-icon name="check" class="ml-auto text-ink-600" /></div><div class="mx-auto h-6 w-px bg-ink-300"></div><div class="grid grid-cols-2 gap-3"><div class="rounded-xl border border-ink-200 bg-white p-4"><x-icon name="pin" class="mb-3" /><p class="text-xs font-semibold">Built for field teams</p></div><div class="rounded-xl border border-brand-primary/50 bg-brand-soft p-4"><x-icon name="shield" class="mb-3" /><p class="text-xs font-semibold">Controlled access</p></div></div></div></div>
        <p class="text-xs text-ink-500">DebtOps — Debt Collection Management System</p>
    </section>
    <section class="flex min-w-0 flex-col justify-center px-5 py-10 sm:px-12 lg:px-10 xl:px-20">
        <div class="mx-auto grid w-full max-w-sm gap-8"><div class="lg:hidden"><x-brand /><p class="mt-4 text-xs text-ink-500">Debt Collection Management System</p></div><div><span class="icon-tile mb-6"><x-icon name="lock" /></span><p class="eyebrow">Welcome to your workspace</p><h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ $title }}</h1>@if($description)<p class="mt-3 text-sm leading-6 text-ink-500">{{ $description }}</p>@endif</div><x-feedback />{{ $slot }}<footer class="flex flex-wrap items-center gap-2 border-t border-ink-200 pt-6 text-[11px] text-ink-500"><span>Developed by InteXcode</span>@unless(app()->isProduction())<x-badge>{{ app()->environment() }}</x-badge>@endunless</footer></div>
    </section>
</main>
@livewireScripts
</body>
</html>
