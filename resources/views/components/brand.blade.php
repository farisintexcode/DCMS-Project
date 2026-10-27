@props(['compact' => false])
<span {{ $attributes->class(['nexora-brand', 'is-compact' => $compact]) }}>
    <span class="nexora-logo-full">
        <img class="nexora-on-light" src="{{ asset(config('dcms.branding.logo')) }}" alt="Nexora" width="420" height="116">
        <img class="nexora-on-dark" src="{{ asset(config('dcms.branding.logo_dark')) }}" alt="Nexora" width="420" height="116">
    </span>
    <span class="nexora-logo-icon">
        <img class="nexora-on-light" src="{{ asset(config('dcms.branding.icon')) }}" alt="Nexora" width="100" height="116">
        <img class="nexora-on-dark" src="{{ asset(config('dcms.branding.icon_dark')) }}" alt="Nexora" width="100" height="116">
    </span>
</span>
