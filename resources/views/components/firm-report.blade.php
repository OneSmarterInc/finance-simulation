@props([
    // column heading => FirmEngineResult (quarters side by side, or firms side by side)
    'columns' => [],
    // column heading => FirmDecisionHistory entry; null hides the Decisions tab
    'decisions' => null,
    // column heading => list<string> accounting-change disclosures (N7)
    'disclosures' => [],
    // scenario parameters, for supplier names in the Decisions tab
    'parameters' => [],
    'initialTab' => 'summary',
    // L15: Claude's reading under each report (QuarterReadings), or null when it's off
    'readings' => null,
])

@php
    use App\Support\Presentation\StatementPresenter;
    use App\Support\Presentation\DecisionPresenter;

    $tabs = ['summary' => 'Summary'];
    if ($decisions !== null) {
        $tabs['decisions'] = 'Decisions';
    }
    $tabs += [
        'income_statement' => 'Income statement',
        'balance_sheet' => 'Balance sheet',
        'cash_flow_statement' => 'Cash flow',
        'notes' => 'Notes',
    ];
    $initialTab = array_key_exists($initialTab, $tabs) ? $initialTab : 'summary';
    $withNotes = collect($columns)->filter(fn ($result) => ! empty($result->notes));
    $disclosures = collect($disclosures)->filter(fn ($items) => ! empty($items));
@endphp

<div x-data="{ tab: @js($initialTab) }" {{ $attributes->class('space-y-4') }}>
    <div role="tablist" aria-label="Reports" class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-white/10">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" x-on:click="tab = @js($key)"
                x-bind:aria-selected="tab === @js($key)"
                x-bind:class="tab === @js($key)
                    ? 'border-emerald-700 text-emerald-800 dark:border-emerald-400 dark:text-emerald-200'
                    : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div x-show="tab === 'summary'" role="tabpanel">
        <x-statement statement="summary" :columns="$columns" caption="Summary" />
        <x-reading :readings="$readings" section="summary" />
    </div>

    @if ($decisions !== null)
        <div x-show="tab === 'decisions'" x-cloak role="tabpanel" class="space-y-3">
            @php($decisionRows = DecisionPresenter::rows($decisions, $parameters))
            @if ($decisionRows === [])
                <flux:text>No decisions are recorded for these quarters yet.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" style="min-width: {{ 18 + count($decisions) * 11 }}rem">
                        <thead>
                            <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600">
                                <th scope="col" class="py-2 pr-4 text-left"><span class="sr-only">Decision</span></th>
                                @foreach (array_keys($decisions) as $heading)
                                    <th scope="col" class="py-2 pl-4 text-right font-semibold text-zinc-700 dark:text-zinc-200">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($decisionRows as $row)
                                @if ($row['type'] === 'heading')
                                    <tr>
                                        <th scope="rowgroup" class="pt-4 pb-1 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $row['label'] }}</th>
                                        @foreach ($row['values'] as $who)
                                            <td class="pt-4 pb-1 pl-4 text-right text-xs text-zinc-500">{{ $who }}</td>
                                        @endforeach
                                    </tr>
                                @else
                                    <tr>
                                        <th scope="row" class="w-72 min-w-64 py-1.5 pr-4 pl-3 text-left font-normal text-zinc-700 dark:text-zinc-300">{{ $row['label'] }}</th>
                                        @foreach ($row['values'] as $value)
                                            <td class="py-1.5 pl-4 text-right whitespace-nowrap">{{ $value }}</td>
                                        @endforeach
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <flux:text size="sm">The small label beside each area says who made the choice: your team, the simulation, or the warm-up that ran the firm before you took over.</flux:text>
            @endif
        </div>
    @endif

    @foreach (StatementPresenter::STATEMENTS as $key => $label)
        <div x-show="tab === @js($key)" x-cloak role="tabpanel">
            <x-statement :statement="$key" :columns="$columns" :caption="$label" />
            <x-reading :readings="$readings" :section="$key" />
        </div>
    @endforeach

    <div x-show="tab === 'notes'" x-cloak role="tabpanel" class="space-y-6">
        @if ($disclosures->isNotEmpty())
            <section class="space-y-2">
                <flux:heading size="lg" level="3">Changes in accounting methods</flux:heading>
                @foreach ($disclosures as $heading => $items)
                    @foreach ($items as $text)
                        <p class="text-sm text-zinc-700 dark:text-zinc-300"><span class="font-semibold">{{ $heading }}.</span> {{ $text }}</p>
                    @endforeach
                @endforeach
            </section>
        @endif

        @if ($withNotes->isEmpty())
            <flux:text>No notes were stored for these quarters.</flux:text>
        @else
            @foreach (StatementPresenter::NOTES as $note => $label)
                <section class="space-y-2">
                    <flux:heading size="lg" level="3">{{ $label }}</flux:heading>
                    <x-statement :statement="$note" :columns="$withNotes->all()" :caption="$label" />
                    @if ($note === 'debt')
                        <flux:text size="sm">A bank overdraft is cash used beyond the firm's committed borrowing facility. It is shown as a current liability, separately from committed debt, and the simulation charges no interest on it.</flux:text>
                    @endif
                </section>
            @endforeach
        @endif
        <x-reading :readings="$readings" section="notes" />
    </div>
</div>
