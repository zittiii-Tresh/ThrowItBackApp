<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'SiteArchive') }}</title>

    {{-- Favicons. Modern browsers prefer SVG; PNG + ICO cover legacy. --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    {{--
      Pre-hydration theme bootstrap — runs before Tailwind styles paint so the
      page doesn't flash light-mode on dark-preference reloads. Reads localStorage
      first, falls back to OS `prefers-color-scheme`.
    --}}
    <script>
        (function () {
            var stored = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (stored === null && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full flex flex-col font-sans">

    <header class="border-b border-surface-200 dark:border-surface-800">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                {{-- Brand mark — three offset rounded squares = stacked snapshots. --}}
                <svg class="h-8 w-8" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect width="32" height="32" rx="7" fill="#534AB7"/>
                    <rect x="6" y="6" width="14" height="14" rx="2.5" fill="#FFFFFF" opacity="0.32"/>
                    <rect x="9" y="9" width="14" height="14" rx="2.5" fill="#FFFFFF" opacity="0.6"/>
                    <rect x="12" y="12" width="14" height="14" rx="2.5" fill="#FFFFFF"/>
                </svg>
                <span class="text-sm font-semibold">SiteArchive</span>
            </a>

            <nav class="flex items-center gap-6 text-sm text-surface-600 dark:text-surface-400">
                {{-- Home is the only universally-accessible link in the user
                     archive. Browse/Viewer/Compare need a site or snapshot
                     context, so they're reached by clicking a chip on Home
                     or a link in Browse — not via the header. --}}
                <a href="{{ route('home') }}" class="hover:text-surface-900 dark:hover:text-surface-100">Home</a>

                <button type="button"
                        onclick="toggleTheme()"
                        class="grid h-8 w-8 place-items-center rounded-md border border-surface-200 dark:border-surface-800 hover:bg-surface-100 dark:hover:bg-surface-800"
                        aria-label="Toggle theme">
                    {{-- Sun icon (visible in dark mode) --}}
                    <svg class="hidden h-4 w-4 dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    {{-- Moon icon (visible in light mode) --}}
                    <svg class="h-4 w-4 dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>

                <a href="{{ url('/admin') }}" class="btn-primary">Admin</a>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <footer class="border-t border-surface-200 py-6 text-center text-xs text-surface-500 dark:border-surface-800 dark:text-surface-300">
        SiteArchive — Internal Tool · Sites at Scale
    </footer>

    <script>
        // Theme toggle: flips .dark on <html> and persists the choice.
        function toggleTheme() {
            var isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        }
    </script>

    @livewireScripts
</body>
</html>
