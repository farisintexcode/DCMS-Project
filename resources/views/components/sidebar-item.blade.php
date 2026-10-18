@props(['label', 'icon' => 'grid', 'href' => null, 'active' => false, 'planned' => false, 'parent' => ''])
<div class="sidebar-entry" data-nav-search="{{ $label.' '.$parent }}" x-show="matches(@js($label.' '.$parent))">
    @if($href)
    <a href="{{ $href }}" class="sidebar-link" aria-label="{{ $label }}" @if($active) aria-current="page" @endif @mouseenter="hint($event, @js($label))" @mouseleave="tooltip = ''" @focus="hint($event, @js($label))" @blur="tooltip = ''" @click="menuOpen = false">
        <x-icon :name="$icon" /><span class="sidebar-label">{{ $label }}</span>
    </a>
    @else
    <span class="sidebar-link sidebar-planned" aria-disabled="true" aria-label="{{ $label }} — planned" tabindex="0" @mouseenter="hint($event, @js($label.' — planned'))" @mouseleave="tooltip = ''" @focus="hint($event, @js($label.' — planned'))" @blur="tooltip = ''"><x-icon :name="$icon" /><span class="sidebar-label">{{ $label }}</span><span class="sidebar-label sidebar-tag">Planned</span></span>
    @endif
</div>
