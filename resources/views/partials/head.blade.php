<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' | ' : '' }}{{ config('dcms.branding.name') }}</title>
<meta name="theme-color" content="#ffffff">
@include('partials.ui-preferences')
<meta name="application-name" content="{{ config('dcms.branding.name') }}">
<meta name="description" content="Nexora — Debt Collection Management System">
<link rel="icon" type="image/png" href="{{ asset(config('dcms.branding.favicon')) }}?v=navy3">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset(config('dcms.branding.apple_touch_icon')) }}?v=rounded2">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles

<link rel="manifest" href="{{ asset('site.webmanifest') }}">
