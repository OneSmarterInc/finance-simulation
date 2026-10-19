<div class="flexee-page">
    <x-page-header
        :eyebrow="$firm->course->name"
        :title="$firm->name.' history'"
        description="Every closed quarter side by side, with the decisions that produced it. Quarters 1 to 3 are the warm-up, run for the firm before the team took over."
    />

    @if ($results->isEmpty())
        <flux:callout icon="clock" heading="No quarters yet" text="The warm-up quarters appear here as soon as the firm is set up." />
    @else
        <flux:card>
            <x-firm-report :columns="$columns" :decisions="$decisionColumns" :disclosures="$disclosureColumns" :parameters="$parameters" />
        </flux:card>
    @endif

    {{-- U11: the team's explanations, quarter by quarter, with the instructor's comments. --}}
    @if ($explanations->isNotEmpty())
        <section class="space-y-3" aria-labelledby="explanations-heading" data-test="history-explanations">
            <flux:heading size="lg" level="2" id="explanations-heading">The team's explanations</flux:heading>
            @foreach ($explanations as $row)
                <flux:card class="space-y-3" wire:key="explanation-{{ $row->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading level="3">Quarter {{ $row->courseQuarter?->quarter_number }}</flux:heading>
                        @if ($row->isSubmitted())
                            <flux:badge color="emerald" size="sm">Written by {{ $row->submittedBy?->user?->name ?? 'the team' }}</flux:badge>
                        @else
                            <flux:badge color="amber" size="sm">Draft only, never sent in</flux:badge>
                        @endif
                    </div>
                    <dl class="grid gap-3 text-sm md:grid-cols-3">
                        @foreach (['changed' => 'What changed?', 'why' => 'Why did it change?', 'notes' => 'What do the notes show?'] as $key => $label)
                            <div class="min-w-0">
                                <dt class="font-medium">{{ $label }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ ($row->answers[$key] ?? '') !== '' ? $row->answers[$key] : 'Not written.' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($row->faculty_comment)
                        <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-white/5">
                            <p class="font-medium">Instructor's comment</p>
                            <p class="mt-1 whitespace-pre-line">{{ $row->faculty_comment }}</p>
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </section>
    @endif
</div>
