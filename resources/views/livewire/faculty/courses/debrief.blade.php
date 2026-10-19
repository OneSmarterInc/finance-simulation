@php
    use App\Services\Debrief\DebriefBuilder;

    $show = collect(array_keys($built['slides']))->reject(fn ($k) => in_array($k, $hidden, true))->values()->all();
@endphp
<div class="flexee-page" @if ($writing) wire:init="writeCaptions" @endif>
    <x-page-header :eyebrow="$course->name" title="Debrief"
        description="Slides built from this class's own results, to present in class. The engine picks every firm, quarter and figure; Claude only words each slide." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif

    @if ($built['slides'] === [])
        <flux:callout icon="presentation-chart-line" heading="Nothing to debrief yet"
            text="The debrief needs at least two firms and one quarter after the warm-up. Come back once Quarter 4 has closed." />
    @else
        @unless ($built['finished'])
            <flux:callout icon="clock" heading="The course is still running"
                :text="'These slides show the course so far, to the end of Quarter '.$built['last_closed'].'. They update as each quarter closes.'" />
        @endunless

        <flux:card class="space-y-4" data-test="debrief-settings">
            @if ($course->is_practice)
                <flux:text size="sm" class="max-w-2xl">This is the debrief your practice run would give a class. It is never shown to students.</flux:text>
            @else
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <flux:heading level="2">{{ $shared ? 'Students can see this debrief' : 'Only you can see this debrief' }}</flux:heading>
                    <flux:text size="sm" class="max-w-2xl">
                        @if ($shared)
                            Each student sees the slides you show here, read-only, from My courses.
                        @elseif ($built['finished'])
                            Share it to put the same slides on each student's My courses page, read-only. Teams are named, as on the reveal.
                        @else
                            You can share it with students once every quarter has closed{{ $course->cf_plus_enabled ? ' and the evaluation stage is settled' : '' }}.
                        @endif
                    </flux:text>
                </div>
                <flux:button wire:click="toggleShared" class="shrink-0" :disabled="! $shared && ! $built['finished']" data-test="debrief-share">
                    {{ $shared ? 'Stop sharing' : 'Share with students' }}
                </flux:button>
            </div>
            @endif
            <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-3 dark:border-white/10">
                <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Slides:</span>
                @foreach ($built['slides'] as $key => $slide)
                    @php
                        $isHidden = in_array($key, $hidden, true);
                    @endphp
                    <flux:button size="sm" wire:click="toggleHidden('{{ $key }}')" :icon="$isHidden ? 'eye-slash' : 'eye'" :variant="$isHidden ? 'ghost' : 'outline'" data-test="toggle-{{ $key }}">
                        {{ $slide['title'] }}{{ $isHidden ? ' (hidden)' : '' }}
                    </flux:button>
                @endforeach
            </div>
            @if ($writing)
                <flux:text size="sm" wire:loading wire:target="writeCaptions" data-test="captions-writing">Claude is writing the slides' captions. The plain sentences show until they arrive.</flux:text>
            @endif
        </flux:card>

        @if ($show === [])
            <flux:callout icon="eye-slash" heading="Every slide is hidden" text="Show a slide above to present it." />
        @else
            <div x-data="{ i: 0, n: {{ count($show) }} }" x-ref="deck"
                x-on:keydown.window.arrow-right="i = Math.min(i + 1, n - 1)" x-on:keydown.window.arrow-left="i = Math.max(i - 1, 0)"
                class="rounded-xl border border-zinc-200 bg-white p-5 sm:p-8 dark:border-white/10 dark:bg-zinc-900 [&:fullscreen]:overflow-y-auto [&:fullscreen]:p-12"
                data-test="debrief-deck">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex gap-2">
                        <flux:button size="sm" icon="chevron-left" x-on:click="i = Math.max(i - 1, 0)" x-bind:disabled="i === 0">Back</flux:button>
                        <flux:button size="sm" icon-trailing="chevron-right" x-on:click="i = Math.min(i + 1, n - 1)" x-bind:disabled="i === n - 1">Next</flux:button>
                    </div>
                    <flux:button size="sm" icon="arrows-pointing-out" x-on:click="document.fullscreenElement ? document.exitFullscreen() : $refs.deck.requestFullscreen().catch(() => {})">Full screen</flux:button>
                </div>
                @include('livewire.debrief.slides', ['built' => $built, 'captions' => $captions, 'show' => $show])
                <p class="mt-6 text-xs text-zinc-500 dark:text-zinc-400">The arrow keys move between slides.</p>
            </div>
        @endif
    @endif
</div>
