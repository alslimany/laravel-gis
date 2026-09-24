{{-- DEPRECATED: legacy Blade+console shell. Primary UI is Inertia via resources/views/app.blade.php. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $consoleProps = $consoleProps ?? \App\Support\ConsoleProps::base('blade');
        $brandColor = $consoleProps['brand']['primaryColor'] ?? '#06b6d4';
    @endphp

    <title>{{ $consoleProps['brand']['name'] ?? 'Lumina GIS' }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700|ibm-plex-mono:400,500,600" rel="stylesheet" />

    <style>
        :root {
            --org-primary: {{ $brandColor }};
            --bs-primary: {{ $brandColor }};
            --bs-primary-rgb: {{ implode(', ', sscanf(ltrim($brandColor, '#'), '%02x%02x%02x') ?: [15, 118, 110]) }};
        }
    </style>

    @viteReactRefresh
    @vite(['resources/sass/app.scss', 'resources/js/console/main.jsx'])
    @stack('styles')
</head>
<body class="antialiased">
    <div
        id="console-root"
        data-props='@json($consoleProps)'
    ></div>

    <div id="console-blade-slot" hidden>
        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>
