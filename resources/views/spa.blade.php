<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        <title>{{ config('app.name') }}</title>
        <script>
            // Apply the saved theme before first paint to avoid a light/dark flash.
            (() => {
                const theme = localStorage.getItem('theme');
                if (theme === 'dark' || (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
        @viteReactRefresh
        @vite(['resources/js/spa/main.tsx'])
    </head>
    <body class="h-full bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
        <div id="root" class="h-full" data-app-name="{{ config('app.name') }}" data-filament-url="{{ url('/admin') }}"></div>
    </body>
</html>
