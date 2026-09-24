<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <script>
        (function () {
            var stored = null;
            try { stored = localStorage.getItem('console.theme'); } catch (e) {}
            var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.classList.toggle('light', !dark);
        })();
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'GIS') }} — Map Builder</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/map-workspace/main.jsx'])
    @stack('styles')
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; background: var(--background); color: var(--foreground); }
    </style>
</head>
<body class="bg-background text-foreground antialiased">
    @yield('content')
    @stack('scripts')
</body>
</html>
