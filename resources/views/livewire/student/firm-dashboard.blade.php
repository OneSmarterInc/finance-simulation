<div class="flexee-page">
    <x-page-header :eyebrow="$firm->course->name" :title="$firm->name.' financial dashboard'" description="Validated firm history. All statements and reports are read-only." />
    @if($selected)
        <div class="flex justify-end"><flux:badge color="emerald" size="lg">Quarter {{ $selected->quarter_number }}</flux:badge></div>
    @endif

    @if($results->isEmpty())
        <flux:callout icon="chart-bar" heading="No validated quarter results are available yet" />
    @else
        <nav aria-label="Quarter history" class="flex flex-wrap gap-2">
            @foreach($results as $result)
                <flux:button type="button" wire:click="selectQuarter({{ $result->quarter_number }})" :variant="$selectedQuarter === $result->quarter_number ? 'primary' : 'outline'">Q{{ $result->quarter_number }}</flux:button>
            @endforeach
        </nav>

        <flux:card aria-labelledby="trend-heading" class="space-y-3">
            <div>
                <h2 id="trend-heading" class="text-lg font-semibold">Quarter-over-quarter trend</h2>
                <p class="text-sm text-zinc-500">Exact stored values; no dashboard-calculated financial metrics.</p>
            </div>
            <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                <table class="w-full min-w-[720px] text-sm tabular-nums">
                    <thead class="border-b border-zinc-300 text-left text-xs uppercase text-zinc-500 dark:border-zinc-600">
                        <tr><th class="py-2">Quarter</th><th class="py-2 text-right">Net revenue</th><th class="py-2 text-right">Cash</th><th class="py-2 text-right">Equity</th><th class="py-2 text-right">Stored operating margin</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($trendRows as $row)
                            <tr><td class="py-2 font-medium">Q{{ $row['quarter'] }}</td><td class="py-2 text-right font-mono">{{ is_numeric($row['revenue']) ? \App\Support\Presentation\FinancialValueFormatter::currency($row['revenue']) : 'Not reported' }}</td><td class="py-2 text-right font-mono">{{ is_numeric($row['cash']) ? \App\Support\Presentation\FinancialValueFormatter::currency($row['cash']) : 'Not reported' }}</td><td class="py-2 text-right font-mono">{{ is_numeric($row['equity']) ? \App\Support\Presentation\FinancialValueFormatter::currency($row['equity']) : 'Not reported' }}</td><td class="py-2 text-right font-mono">{{ is_numeric($row['operating_margin']) ? number_format((float) $row['operating_margin'], 4) : 'Not reported' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>

        @if($selected)
            <section class="flex flex-wrap items-center gap-3">
                <h2 class="text-xl font-semibold">Quarter {{ $selected->quarter_number }}</h2>
                @if($selected->provenance === \App\Models\FirmEngineResult::PROVENANCE_WARMUP)
                    <flux:badge color="sky" icon="lock-closed">System-generated inherited read-only history</flux:badge>
                @else
                    <flux:badge color="emerald" icon="lock-closed">Closed student quarter - read-only</flux:badge>
                @endif
            </section>

            <flux:card aria-labelledby="income-heading" class="space-y-3">
                <h2 id="income-heading" class="text-lg font-semibold">Income Statement</h2>
                <x-financial-array :values="$selected->income_statement" currency />
            </flux:card>

            <flux:card aria-labelledby="balance-heading" class="space-y-3">
                <h2 id="balance-heading" class="text-lg font-semibold">Balance Sheet</h2>
                <x-financial-array :values="$selected->balance_sheet" currency />
            </flux:card>

            <flux:card aria-labelledby="cash-heading" class="space-y-3">
                <h2 id="cash-heading" class="text-lg font-semibold">Cash Flow Statement</h2>
                <x-financial-array :values="$selected->cash_flow_statement" currency />
                <div class="flex justify-between border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700"><span>Dividend paid (stored financing movement)</span><strong class="font-mono">{{ \App\Support\Presentation\FinancialValueFormatter::currency($selected->fields['cf_equity'] ?? 0) }}</strong></div>
            </flux:card>

            <section aria-labelledby="overdraft-heading" class="space-y-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <h2 id="overdraft-heading" class="text-lg font-semibold">Bank Overdraft</h2>
                    <span @class([
                        'text-sm font-semibold',
                        'text-red-700 dark:text-red-300' => (float) ($selected->fields['n5_shortfall_indicator'] ?? 0) === 1.0,
                        'text-zinc-500' => (float) ($selected->fields['n5_shortfall_indicator'] ?? 0) !== 1.0,
                    ])>{{ (float) ($selected->fields['n5_shortfall_indicator'] ?? 0) === 1.0 ? 'Financial shortfall indicated' : 'No financial shortfall indicated' }}</span>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Bank overdraft represents cash usage beyond the firm's committed borrowing facility and is presented separately from committed debt.</p>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div class="border-l-2 border-zinc-300 pl-3 dark:border-zinc-600"><dt class="text-zinc-500">Current liability</dt><dd class="font-mono font-semibold">{{ \App\Support\Presentation\FinancialValueFormatter::currency($selected->fields['bs_bank_overdraft'] ?? 0) }}</dd></div>
                    <div class="border-l-2 border-zinc-300 pl-3 dark:border-zinc-600"><dt class="text-zinc-500">Financing movement</dt><dd class="font-mono font-semibold">{{ \App\Support\Presentation\FinancialValueFormatter::currency($selected->fields['cf_overdraft_chg'] ?? 0) }}</dd></div>
                </dl>
                <p class="text-xs text-zinc-500">The current simulation does not apply an interest cost to bank overdraft.</p>
            </section>

            <section aria-labelledby="notes-heading" class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <div><h2 id="notes-heading" class="text-lg font-semibold">Notes and Supplementary Reports</h2><p class="text-sm text-zinc-500">Every report persisted by the engine is shown below.</p></div>
                @forelse($selected->notes as $report => $values)
                    <section class="space-y-2 border-t border-zinc-200 pt-4 first:border-0 first:pt-0 dark:border-zinc-700">
                        <h3 class="font-semibold">{{ str($report)->replace('_', ' ')->title() }} report</h3>
                        @if(is_array($values))<x-financial-array :values="$values" currency :non-monetary-keys="$nonMonetaryNoteKeys" />@else<p>{{ $values }}</p>@endif
                    </section>
                @empty
                    <p class="text-sm text-zinc-500">No supplementary reports were stored for this quarter.</p>
                @endforelse
            </section>
        @endif
    @endif
</div>
