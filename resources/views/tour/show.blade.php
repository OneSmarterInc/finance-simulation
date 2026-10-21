@php
    use App\Support\Tour\AdopterTour as T;
    $total = count($steps);
    $whoColor = ['student' => 'bg-sky-100 text-sky-900 dark:bg-sky-400/15 dark:text-sky-200', 'faculty' => 'bg-amber-100 text-amber-900 dark:bg-amber-400/15 dark:text-amber-200', 'both' => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-400/15 dark:text-emerald-200'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>How Flexee works · {{ $current['short'] }}</title>
        <meta name="description" content="A nine-step tour of {{ \App\Support\Product::NAME }} for faculty thinking of adopting it: what students see, what the instructor sees, and what the course teaches.">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="border-b border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-md bg-emerald-700 text-white"><x-app-logo-icon class="size-5" /></span>
                    <span class="leading-tight">
                        <span class="block text-sm font-semibold">{{ \App\Support\Product::NAME }}</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">How it works</span>
                    </span>
                </a>
                <a href="{{ route('login') }}" class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-white/20 dark:hover:bg-white/10">Sign in</a>
            </div>
        </header>

        <main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            {{-- The steps --}}
            <nav aria-label="Tour steps" class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <ol class="flex min-w-max gap-2">
                    @foreach ($steps as $i => $s)
                        @php
                            $n = $i + 1;
                        @endphp
                        <li>
                            <a href="{{ route('tour', $n) }}" @if ($n === $step) aria-current="step" @endif
                                class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm {{ $n === $step ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-zinc-300 bg-white text-zinc-700 hover:border-zinc-400 dark:border-white/15 dark:bg-zinc-900 dark:text-zinc-300' }}">
                                <span class="font-semibold tabular-nums">{{ $n }}</span> {{ $s['short'] }}
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-start">
                {{-- The story --}}
                <article class="min-w-0 space-y-4" data-test="tour-story">
                    <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $whoColor[$current['who']] }}">{{ T::who($current['who']) }}</span>
                    {{-- Live review R31: on a phone the step row may be scrolled away, so the step is named here too. --}}
                    <p class="text-sm text-zinc-500 sm:hidden dark:text-zinc-400" data-test="tour-step-count">Step {{ $step }} of {{ $total }}</p>
                    <h1 class="text-2xl font-semibold text-balance sm:text-3xl">{{ $current['title'] }}</h1>
                    @foreach ($current['story'] as $paragraph)
                        <p class="max-w-prose leading-7 text-zinc-700 dark:text-zinc-300">{!! $paragraph !!}</p>
                    @endforeach
                    <div class="max-w-prose rounded-lg border-l-4 border-emerald-700 bg-emerald-50 px-4 py-3 dark:border-emerald-400 dark:bg-emerald-400/10">
                        <p class="text-xs font-semibold tracking-wide text-emerald-800 uppercase dark:text-emerald-300">The point for your course</p>
                        <p class="mt-1 text-sm leading-6">{{ $current['lesson'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        @if ($step > 1)
                            <a href="{{ route('tour', $step - 1) }}" class="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-white/20 dark:hover:bg-white/10" data-tour-prev>Back</a>
                        @endif
                        @if ($step < $total)
                            <a href="{{ route('tour', $step + 1) }}" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800" data-tour-next>Next</a>
                        @endif
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">Step {{ $step }} of {{ $total }}</span>
                    </div>
                </article>

                {{-- The screen --}}
                <figure class="min-w-0" aria-label="The screen at this step">
                    @include('tour.screens.step'.$step)
                </figure>
            </div>

            <p class="max-w-3xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                Every figure in this tour comes from a practice run of a reference course, played by the same engine a class uses, with the Careful, Average and Careless reference teams over {{ $x['quarters'] }} quarters. Names of students, instructors and firms are made up.
            </p>
        </main>

        <script>
            // Live review R31: bring the current step's button into view in the step row (phones).
            document.querySelector('[aria-current="step"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
            document.addEventListener('keydown', (e) => {
                if (e.target.closest('input, textarea, select') || e.metaKey || e.ctrlKey || e.altKey) return;
                const link = document.querySelector(e.key === 'ArrowRight' ? '[data-tour-next]' : e.key === 'ArrowLeft' ? '[data-tour-prev]' : null);
                if (link) window.location = link.href;
            });
        </script>
    </body>
</html>
