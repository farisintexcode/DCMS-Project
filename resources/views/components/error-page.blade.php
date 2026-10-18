@props(['code', 'title', 'message'])
<x-layouts.guest :title="$title" :description="$message">
    <div class="flex items-center gap-3 rounded-2xl border border-ink-200 bg-ink-50 p-5"><span class="text-3xl font-semibold tracking-tight">{{ $code }}</span><span class="text-xs leading-6 text-ink-500">DebtOps could not open this page.<br>Use the link below to continue.</span></div>
    @auth<a href="{{ route('account.settings') }}" class="btn btn-primary"><x-icon name="user" class="size-4" />Go to your account</a>@else<a href="{{ route('login') }}" class="btn btn-primary"><x-icon name="arrow-left" class="size-4" />Back to sign in</a>@endauth
</x-layouts.guest>
