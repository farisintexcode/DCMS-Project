<section class="rider-page rider-profile-page">
    <x-feedback />
    <x-page-header title="My Profile" eyebrow="" description="Your personal details and employment information." />
    <section id="profile-information" class="rider-profile-header" aria-label="Rider profile">
        <x-profile-photo-editor :on-dark="true" />
        <div class="min-w-0 flex-1"><h2 class="break-words text-xl font-semibold sm:text-2xl">{{ $staff->full_name ?: $user->name }}</h2><p class="mt-2 text-sm text-white/80">{{ $staff->staff_no ?: 'Employee ID not provided' }}</p><div class="mt-4 flex flex-wrap items-center gap-2"><span class="rounded-full border border-white/20 px-3 py-1 text-xs">{{ $roles->isNotEmpty() ? $roles->join(', ') : ($staff->is_rider ? 'Rider' : 'Role not assigned') }}</span><span class="inline-flex items-center gap-2 rounded-full border border-white/20 px-3 py-1 text-xs"><span class="size-1.5 rounded-full bg-brand-accent" aria-hidden="true"></span>{{ $user->isActive() ? 'Active' : 'Inactive' }}</span></div></div>
    </section>
    <section class="surface rider-account-card" aria-labelledby="personal-information-title">
        <div class="flex items-center gap-3"><span class="icon-tile"><x-icon name="user" /></span><div><h2 id="personal-information-title" class="text-base font-semibold">Personal information</h2><p class="mt-1 text-xs leading-5 text-ink-500">Your registered details, kept in one place.</p></div></div>
        <dl class="mt-6 grid gap-x-8 sm:grid-cols-2">
            @foreach(['Full Name' => $staff->full_name ?: $user->name, 'Employee ID' => $staff->staff_no, 'Email Address' => $user->email, 'Phone Number' => $staff->phone_ciphertext, 'Role' => $roles->isNotEmpty() ? $roles->join(', ') : ($staff->is_rider ? 'Rider' : null), 'Position' => $staff->position?->label, 'Account Status' => $user->isActive() ? 'Active' : 'Inactive'] as $label => $value)
                <div class="min-w-0 border-t border-ink-100 py-4"><dt class="text-xs text-ink-500">{{ $label }}</dt><dd class="mt-2 break-words text-sm font-medium">{{ $value ?: 'Not provided' }}</dd></div>
            @endforeach
        </dl>
        <p class="mt-2 flex items-start gap-2 rounded-xl bg-brand-soft p-4 text-xs leading-6 text-ink-600"><x-icon name="lock" class="mt-1 size-4" />These details are managed by your administrator. Contact them if your personal or employment information needs updating.</p>
    </section>
</section>
