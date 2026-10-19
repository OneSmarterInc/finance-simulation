@php
    $show = collect(array_keys($built['slides']))->reject(fn ($k) => in_array($k, $hidden, true))->values()->all();
@endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Class debrief"
        description="How the whole class did, from the course's own results. Your instructor chose to share these slides after the course." />

    @if ($show === [])
        <flux:callout icon="presentation-chart-line" heading="No slides to show" text="Your instructor hasn't shared any slides." />
    @else
        <div x-data="{ i: 0, n: {{ count($show) }} }"
            x-on:keydown.window.arrow-right="i = Math.min(i + 1, n - 1)" x-on:keydown.window.arrow-left="i = Math.max(i - 1, 0)"
            class="rounded-xl border border-zinc-200 bg-white p-5 sm:p-8 dark:border-white/10 dark:bg-zinc-900" data-test="debrief-deck">
            <div class="mb-6 flex gap-2">
                <flux:button size="sm" icon="chevron-left" x-on:click="i = Math.max(i - 1, 0)" x-bind:disabled="i === 0">Back</flux:button>
                <flux:button size="sm" icon-trailing="chevron-right" x-on:click="i = Math.min(i + 1, n - 1)" x-bind:disabled="i === n - 1">Next</flux:button>
            </div>
            @include('livewire.debrief.slides', ['built' => $built, 'captions' => $captions, 'show' => $show])
        </div>
    @endif
</div>
