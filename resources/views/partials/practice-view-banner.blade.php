{{--
    U9 view-as (FF5): on every page while a faculty member views a practice
    run as one of its reference teams, with one click back to faculty.
--}}
@php
    $practiceView = app(\App\Services\Practice\PracticeViewAs::class)->current(request());
@endphp

@if ($practiceView)
    <div role="status" class="sticky top-2 z-40 mb-6 rounded-lg border border-amber-300 bg-amber-100 text-amber-950 shadow-sm dark:border-amber-500/40 dark:bg-amber-950 dark:text-amber-100">
        <div class="flex flex-col gap-2 px-4 py-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm">
                <span class="font-semibold">Practice run</span>
                · viewing as {{ \App\Services\Practice\ReferenceTeams::NAMES[$practiceView['team']] ?? 'a team' }}.
                You see what its students see.
            </p>
            <form method="POST" action="{{ route('practice.back') }}" class="shrink-0">
                @csrf
                <flux:button type="submit" size="sm" icon="arrow-uturn-left">Back to faculty view</flux:button>
            </form>
        </div>
        @if (session('status') && str_contains((string) session('status'), 'practice run'))
            <p class="px-4 pb-2 text-sm">{{ session('status') }}</p>
        @endif
    </div>
@endif
