<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>CF Manufacturing Rebuild</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
        <main class="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-16">
            <div class="space-y-8">
                <div class="space-y-3">
                    <p class="text-sm font-medium uppercase tracking-normal text-emerald-300">FLEXEE system status</p>
                    <h1 class="text-4xl font-semibold tracking-normal text-white">CF Manufacturing app is running</h1>
                    <p class="max-w-2xl text-base leading-7 text-zinc-300">
                        The CF Manufacturing learning environment, application routing, and database connection are available.
                    </p>
                </div>

                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="border border-zinc-800 bg-zinc-900/70 p-5">
                        <dt class="text-sm text-zinc-400">Framework version</dt>
                        <dd class="mt-2 text-xl font-semibold text-white">{{ $laravelVersion }}</dd>
                    </div>

                    <div class="border border-zinc-800 bg-zinc-900/70 p-5">
                        <dt class="text-sm text-zinc-400">Database status</dt>
                        <dd class="mt-2 text-xl font-semibold {{ $databaseAvailable ? 'text-emerald-300' : 'text-amber-300' }}">
                            {{ $databaseAvailable ? 'Database connected' : 'Database unavailable' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </main>
    </body>
</html>
