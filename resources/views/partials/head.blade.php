<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'DCMS' }} · InteXcode</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
