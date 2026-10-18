<!DOCTYPE html>
<html lang="en">
<head>
    {!! \Laravel\Pulse\Facades\Pulse::css() !!}
    @include('partials.head', ['title' => 'System monitoring'])
    {!! \Laravel\Pulse\Facades\Pulse::js() !!}
    @livewireScriptConfig
</head>
<body class="min-h-dvh bg-white font-sans text-brand-dark antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only">Skip to content</a>
    <header class="border-t-2 border-b border-ink-200 border-t-brand-primary px-5 py-5">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5"><x-brand /><div class="flex flex-wrap items-center gap-5"><a class="text-sm underline" href="{{ route('admin.home') }}">Admin workspace</a><livewire:pulse.period-selector /></div></div>
    </header>
    <main id="main-content" class="mx-auto max-w-7xl px-5 py-8">
        <h1 class="mb-6 text-2xl font-semibold">System monitoring</h1>
        <div class="grid default:grid-cols-12 default:gap-6">{{ $slot }}</div>
    </main>
</body>
</html>
