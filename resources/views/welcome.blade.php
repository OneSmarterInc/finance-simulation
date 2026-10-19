<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ \App\Support\Product::NAME }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <main class="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-16">
            <div class="space-y-8">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-md bg-emerald-700 text-white">
                        <x-app-logo-icon class="size-6" />
                    </span>
                    <span class="text-sm font-medium text-emerald-800 dark:text-emerald-300">Flexee</span>
                </div>

                <div class="space-y-4">
                    <h1 class="text-4xl font-semibold tracking-normal">{{ \App\Support\Product::NAME }}</h1>
                    <p class="max-w-2xl text-lg leading-8 text-zinc-600 dark:text-zinc-300">
                        A course simulation in reading financial statements. Each team runs a manufacturing firm, and every quarter brings a new income statement, balance sheet, cash flow statement and notes to explain.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Go to your courses</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Sign in</a>
                    @endauth
                    {{-- Live walkthrough L8: a tour for faculty thinking of adopting Flexee, no sign-in needed. --}}
                    <a href="{{ route('tour') }}" class="rounded-md border border-zinc-300 bg-white px-5 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-100 dark:border-white/20 dark:bg-transparent dark:text-white dark:hover:bg-white/10" data-test="see-how-it-works">See how it works</a>
                </div>

                <p class="max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                    Students join from the course link their instructor sends. If you haven't received one yet, ask your instructor for it.
                </p>
            </div>
        </main>
    </body>
</html>
