<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Quarter results"
        description="One report at a time. Read one firm across every quarter, compare all firms in one quarter, or follow one measure for every firm.">
        @if ($quarters->isNotEmpty())
            <x-slot:actions>
                <flux:button icon="arrow-down-tray" :href="route('faculty.courses.quarter-results.download', $course)" data-test="results-download">Download (.xlsx)</flux:button>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- L15 (N6): the switches for Claude's reading under each report. --}}
    @if ($readingsReady)
        <flux:card class="space-y-3" data-test="reading-switches">
            <div class="space-y-1">
                <flux:heading level="2">Claude's reading of each quarter</flux:heading>
                <flux:text size="sm" class="max-w-3xl">
                    Under each report, a short "What this shows" paragraph, written once when the quarter closes and checked against the stored results. It describes what happened and never tells teams what to decide.
                    @if ($course->interpretations_required)
                        A team sees its reading of a quarter only after it has explained that quarter, or once the deadline for that has passed.
                    @else
                        This course doesn't ask for explanations, so when it's shown to students, a team sees its reading as soon as the quarter closes.
                    @endif
                </flux:text>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-6">
                <div class="flex items-center gap-3">
                    <flux:badge size="sm" :color="$course->ai_reading_faculty ? 'emerald' : 'zinc'">{{ $course->ai_reading_faculty ? 'Shown to you' : 'Not shown to you' }}</flux:badge>
                    <flux:button size="sm" wire:click="toggleReading('faculty')" data-test="reading-toggle-faculty">{{ $course->ai_reading_faculty ? 'Hide from me' : 'Show to me' }}</flux:button>
                </div>
                <div class="flex items-center gap-3">
                    <flux:badge size="sm" :color="$course->ai_reading_students ? 'emerald' : 'zinc'">{{ $course->ai_reading_students ? 'Shown to students' : 'Not shown to students' }}</flux:badge>
                    <flux:button size="sm" wire:click="toggleReading('students')" data-test="reading-toggle-students">{{ $course->ai_reading_students ? 'Hide from students' : 'Show to students' }}</flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    @if ($firms->isEmpty())
        <flux:callout icon="chart-bar" heading="No firms yet" text="Results appear here once firms are created and their warm-up quarters are ready." />
    @else
        <flux:card class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">One firm:</span>
                    @foreach ($firms as $firm)
                        <flux:button size="sm" type="button" wire:click="showFirm('{{ $firm->id }}')"
                            :variant="$mode === 'firm' && $selectedFirm === $firm->id ? 'primary' : 'outline'">
                            {{ $firm->name }}
                        </flux:button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" type="button" wire:click="showCompare" icon="table-cells"
                        :variant="$mode === 'compare' ? 'primary' : 'outline'">All firms, every quarter</flux:button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">All firms in:</span>
                    @foreach ($quarters as $number)
                        <flux:button size="sm" type="button" wire:click="showQuarter({{ $number }})"
                            :variant="$mode === 'quarter' && $selectedQuarter === $number ? 'primary' : 'outline'">
                            Q{{ $number }}
                        </flux:button>
                    @endforeach
                </div>
            </div>

            @if ($mode === 'compare')
                @include('livewire.faculty.courses.partials.results-compare')
            @elseif ($columns === [])
                <flux:text>No results are available for this selection yet.</flux:text>
            @else
                <flux:heading size="lg" level="2">
                    {{ $mode === 'firm' ? ($firms->firstWhere('id', $selectedFirm)?->name.', every quarter') : 'Quarter '.$selectedQuarter.', every firm' }}
                </flux:heading>
                <x-firm-report wire:key="report-{{ $mode }}-{{ $selectedFirm }}-{{ $selectedQuarter }}"
                    :columns="$columns" :decisions="$decisionColumns" :disclosures="$disclosureColumns" :parameters="$parameters" :readings="$readings" />
            @endif
        </flux:card>
    @endif
</div>
