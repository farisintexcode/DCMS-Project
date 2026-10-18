@props(['id'])
<div id="{{ $id }}" class="sidebar-submenu" x-cloak x-show="!rail && (groupOpen || !!query.trim())" x-transition.opacity.duration.150ms>{{ $slot }}</div>
