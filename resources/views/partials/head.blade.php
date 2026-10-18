<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' | ' : '' }}{{ config('dcms.branding.name') }}</title>
<meta name="theme-color" content="#ffffff">
@include('partials.ui-preferences')
<meta name="application-name" content="{{ config('dcms.branding.name') }}">
<meta name="description" content="DebtOps — Debt Collection Management System by InteXcode">
<link rel="icon" type="image/png" href="{{ asset(config('dcms.branding.icon')) }}">
<link rel="apple-touch-icon" href="{{ asset(config('dcms.branding.icon')) }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
