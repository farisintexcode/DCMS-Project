<section class="grid gap-6">
    <div><p class="mb-2 text-xs font-semibold tracking-widest text-teal-700 uppercase">Rider workspace</p><h1 class="text-2xl font-semibold tracking-tight">Welcome, {{ auth()->user()->name }}</h1></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-lg font-semibold">Your field workspace</h2><p class="mt-3 text-sm leading-6 text-slate-600">My Tasks and attendance are being prepared in the next development phases. Your account is ready.</p><p class="mt-5 text-xs text-slate-500">{{ config('dcms.business_timezone') }}</p></div>
    <a href="{{ route('account.settings') }}" class="min-h-12 rounded-xl border border-teal-700 px-5 py-4 text-center text-sm font-semibold text-teal-800">Manage your account</a>
</section>
