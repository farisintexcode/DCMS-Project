@props(['compact' => false])
<span {{ $attributes->class(['debtops-brand', 'is-compact' => $compact]) }}>
    <span class="debtops-logo-full"><img src="{{ asset(config('dcms.branding.logo')) }}" alt="DebtOps" width="906" height="906"></span>
    <span class="debtops-logo-icon"><img src="{{ asset(config('dcms.branding.icon')) }}" alt="DebtOps" width="1414" height="1112"></span>
</span>
