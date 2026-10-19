@props([
    // QuarterReadings::forFaculty / forStudents: ['audience' => faculty|students, 'entries' => column heading => entry]
    'readings' => null,
    // summary | income_statement | balance_sheet | cash_flow_statement | notes
    'section',
])

@if ($readings && ! empty($readings['entries']))
    @php
        $entries = $readings['entries'];
        $labels = array_keys($entries);
        // Newest first: the last column with a reading, else the last column.
        $default = collect($entries)->filter(fn ($entry) => $entry['state'] === 'written')->keys()->last() ?? end($labels);
        $students = $readings['audience'] === 'students';
    @endphp
    <section x-data="{ pick: @js($default) }" data-test="reading-{{ $section }}" aria-label="What this shows"
        class="mt-6 rounded-xl border border-sky-200 bg-sky-50/70 p-4 dark:border-sky-400/20 dark:bg-sky-400/5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <flux:icon.sparkles variant="micro" class="text-sky-700 dark:text-sky-300" />
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">What this shows</h3>
            </div>
            @if (count($labels) > 1)
                <div class="flex flex-wrap gap-1" role="group" aria-label="Choose whose reading to show">
                    @foreach ($labels as $label)
                        <button type="button" x-on:click="pick = @js($label)" x-bind:aria-pressed="pick === @js($label)"
                            x-bind:class="pick === @js($label)
                                ? 'bg-sky-700 text-white dark:bg-sky-500 dark:text-zinc-950'
                                : 'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10'"
                            class="rounded-md px-2 py-0.5 text-xs font-medium">{{ str_replace(' · warm-up', '', $label) }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        @foreach ($entries as $label => $entry)
            <div x-show="pick === @js($label)" @if ($label !== $default) x-cloak @endif class="mt-2 space-y-2">
                @if ($entry['state'] === 'written' && filled($entry['sections'][$section] ?? null))
                    <p class="max-w-3xl text-sm leading-6 text-zinc-800 dark:text-zinc-200">{{ $entry['sections'][$section] }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Written by Claude after Quarter {{ $entry['quarter'] }} closed, from the stored results.
                        {{ $students ? 'It describes what happened. It doesn\'t tell you what to decide.' : 'It describes what happened and never tells the team what to decide.' }}
                        @if (! $students && ($entry['dropped'] ?? 0) > 0)
                            {{ $entry['dropped'] }} {{ str('sentence')->plural($entry['dropped']) }} in this quarter's reading failed the checks and {{ $entry['dropped'] === 1 ? 'was' : 'were' }} left out.
                        @endif
                    </p>
                @elseif ($entry['state'] === 'written')
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">There's no reading of this report for Quarter {{ $entry['quarter'] }}, because every sentence Claude wrote about it failed the checks.</p>
                @else
                    <p class="text-sm text-zinc-600 dark:text-zinc-300" data-test="reading-{{ $entry['state'] }}">{{ $entry['message'] }}</p>
                @endif
            </div>
        @endforeach
    </section>
@endif
