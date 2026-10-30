@props(['title' => 'Sign in', 'description' => 'Use your staff account to continue securely.', 'card' => false, 'rider' => false])
<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head', ['title' => $title])</head>
<body @class(['bg-white text-brand-dark antialiased', 'auth-reference' => $card])><div class="absolute top-4 right-4 z-10"><x-theme-toggle /></div>
<main id="main-content" @class(['grid min-h-dvh lg:grid-cols-2', 'auth-frame' => $card])>
    <section class="auth-art relative flex flex-col justify-between overflow-hidden border-r border-ink-200 p-6 sm:p-8 lg:p-12 xl:p-16">
        <x-brand />
        <x-welcome-carousel :rider="$rider" />
        <p class="text-xs text-ink-500">Nexora — Debt Collection Management System</p>
    </section>
    <section @class(['auth-panel flex min-w-0 flex-col items-center justify-center px-4 py-8 sm:px-8 sm:py-12 lg:px-8 xl:px-12', 'has-auth-card' => $card])>
        <div @class(['mx-auto grid w-full', 'auth-card gap-6' => $card, 'max-w-sm gap-8' => !$card])><div><span class="icon-tile mb-6"><x-icon :name="$rider ? 'bike' : 'lock'" /></span>@unless($rider)<p class="eyebrow">Welcome to your workspace</p>@endunless<h1 @class(['text-3xl font-semibold tracking-tight', 'mt-3' => !$rider])>{{ $title }}</h1>@if($description)<p class="mt-3 text-sm leading-6 text-ink-500">{{ $description }}</p>@endif</div><x-feedback />{{ $slot }}<footer class="flex flex-wrap items-center gap-2 border-t border-ink-200 pt-6 text-[11px] text-ink-500"><span>Nexora</span>@unless(app()->isProduction())<x-badge>{{ app()->environment() }}</x-badge>@endunless</footer></div>
    </section>
</main>
@livewireScripts
</body>
</html>
