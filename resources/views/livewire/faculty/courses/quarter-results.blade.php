<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Quarter results"
        description="One report at a time. Read one firm across every quarter, compare all firms in one quarter, or follow one measure for every firm.">
        @if ($quarters->isNotEmpty())
            <x-slot:actions>
                <flux:button icon="arrow-down-tray" :href="route('faculty.courses.quarter-results.download', $course)" data-test="results-download">Download (.xlsx)</flux:button>
            </x-slot:actions>
        @endif
    </x-page-header>

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
                    :columns="$columns" :decisions="$decisionColumns" :disclosures="$disclosureColumns" :parameters="$parameters" />
            @endif
        </flux:card>
    @endif
</div>
