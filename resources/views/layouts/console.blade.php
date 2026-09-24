{{-- DEPRECATED: legacy console entry. Prefer Inertia (resources/views/app.blade.php + resources/js/app.jsx). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ ($consoleProps['brand']['name'] ?? null) ?: 'Lumina GIS' }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700|ibm-plex-mono:400,500,600" rel="stylesheet" />

    @php
        $brandColor = $consoleProps['brand']['primaryColor'] ?? '#0f766e';
    @endphp
    <style>
        :root {
            --org-primary: {{ $brandColor }};
            --bs-primary: {{ $brandColor }};
        }
    </style>

    {{-- Keep Bootstrap available for existing Blade forms/tables inside the shell --}}
    @viteReactRefresh
    @vite(['resources/sass/app.scss', 'resources/js/console/main.jsx'])
    @stack('styles')
</head>
<body class="antialiased">
    <div
        id="console-root"
        data-props='@json($consoleProps)'
    ></div>

    @hasSection('content')
        <div id="console-blade-slot" hidden>
            @yield('content')
        </div>
    @endif

    @stack('scripts')
</body>
</html>
