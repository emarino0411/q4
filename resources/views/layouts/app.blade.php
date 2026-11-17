<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script>
            @auth
            localStorage.setItem('theme', '{{ auth()->user()->theme }}');
            @endauth
            if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        </script>
    </head>
    <body class="font-sans antialiased bg-wimbledon-50 dark:bg-gray-950 min-h-screen transition-colors duration-200">
        <livewire:layout.navigation />

        @if (isset($header))
            <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                    @if (is_string($header))
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $header }}</h2>
                    @else
                        {{ $header }}
                    @endif
                </div>
            </header>
        @endif

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 transition-all duration-300">
            {{ $slot }}
        </main>
    </body>
</html>
