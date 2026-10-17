<div class="flexee-page">

    <x-page-header
        :eyebrow="$course->name"
        title="Quarter Results"
        description="Read-only financial results for each firm and quarter."
    />

    <div class="mb-5">
        <flux:button
            :href="route('dashboard')"
            icon="arrow-left"
            variant="ghost"
        >
            Back to Courses
        </flux:button>
    </div>

    @if ($firms->isEmpty())

        <flux:callout
            icon="chart-bar"
            heading="No firms are available"
        >
            No firms have been created for this simulation yet.
        </flux:callout>

    @else

        {{-- Firm selector --}}
        <flux:card class="space-y-4">

            <div>
                <flux:heading size="lg">
                    Select Firm
                </flux:heading>

                <flux:text class="mt-1">
                    Select a firm to view its quarter-by-quarter financial results.
                </flux:text>
            </div>

            <div class="flex flex-wrap gap-2">

                @foreach ($firms as $firm)

                    <flux:button
                        type="button"
                        wire:click="selectFirm('{{ $firm->id }}')"
                        :variant="$selectedFirm === $firm->id ? 'primary' : 'outline'"
                    >
                        {{ $firm->code }} — {{ $firm->name }}
                    </flux:button>

                @endforeach

            </div>

        </flux:card>

        @if ($results->isEmpty())

            <flux:callout
                class="mt-5"
                icon="clock"
                heading="No quarter results are available yet"
            >
                This firm does not have any validated quarter results yet.
            </flux:callout>

        @else

            {{-- Quarter selector --}}
            <div class="mt-5 flex flex-wrap items-center gap-2">

                <flux:text class="mr-2 font-medium">
                    Quarter:
                </flux:text>

                @foreach ($results as $result)

                    <flux:button
                        type="button"
                        wire:click="selectQuarter({{ $result->quarter_number }})"
                        :variant="$selectedQuarter === $result->quarter_number ? 'primary' : 'outline'"
                    >
                        Q{{ $result->quarter_number }}
                    </flux:button>

                @endforeach

            </div>

            {{-- Quick summary --}}
            <flux:card class="mt-5 space-y-4">

                <div>
                    <flux:heading size="lg">
                        Quarter-over-quarter trend
                    </flux:heading>

                    <flux:text class="mt-1">
                        Exact stored values; no dashboard-calculated financial metrics.
                    </flux:text>
                </div>

                <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">

                    <table class="w-full min-w-[720px] text-sm tabular-nums">

                        <thead class="border-b border-zinc-300 text-left text-xs uppercase text-zinc-500 dark:border-zinc-600">
                            <tr>
                                <th class="px-3 py-2">Quarter</th>
                                <th class="px-3 py-2 text-right">Net revenue</th>
                                <th class="px-3 py-2 text-right">Cash</th>
                                <th class="px-3 py-2 text-right">Equity</th>
                                <th class="px-3 py-2 text-right">Stored operating margin</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">

                            @foreach ($trendRows as $row)

                                <tr
                                    class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5"
                                    wire:click="selectQuarter({{ $row['quarter'] }})"
                                >
                                    <td class="px-3 py-2 font-medium">
                                        Q{{ $row['quarter'] }}
                                    </td>

                                    <td class="px-3 py-2 text-right font-mono">
                                        {{ is_numeric($row['revenue'])
                                            ? \App\Support\Presentation\FinancialValueFormatter::currency($row['revenue'])
                                            : 'Not reported' }}
                                    </td>

                                    <td class="px-3 py-2 text-right font-mono">
                                        {{ is_numeric($row['cash'])
                                            ? \App\Support\Presentation\FinancialValueFormatter::currency($row['cash'])
                                            : 'Not reported' }}
                                    </td>

                                    <td class="px-3 py-2 text-right font-mono">
                                        {{ is_numeric($row['equity'])
                                            ? \App\Support\Presentation\FinancialValueFormatter::currency($row['equity'])
                                            : 'Not reported' }}
                                    </td>

                                    <td class="px-3 py-2 text-right font-mono">
                                        {{ is_numeric($row['operating_margin'])
                                            ? number_format((float) $row['operating_margin'], 4)
                                            : 'Not reported' }}
                                    </td>
                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

            </flux:card>

            @if ($selected)

                {{-- Selected quarter --}}
                <section class="mt-5 space-y-5">

                    <div class="flex flex-wrap items-center gap-3">

                        <flux:heading size="xl">
                            Quarter {{ $selected->quarter_number }}
                        </flux:heading>

                        @php
                            $selectedFirmModel = $firms->firstWhere('id', $selectedFirm);
                        @endphp

                        @if ($selectedFirmModel)
                            <flux:badge color="zinc">
                                {{ $selectedFirmModel->code }}
                            </flux:badge>
                        @endif

                        @if ($selected->provenance === \App\Models\FirmEngineResult::PROVENANCE_WARMUP)
                            <flux:badge color="sky" icon="lock-closed">
                                System-generated inherited read-only history
                            </flux:badge>
                        @else
                            <flux:badge color="emerald" icon="lock-closed">
                                Closed student quarter — read-only
                            </flux:badge>
                        @endif

                    </div>

                    {{-- Income Statement --}}
                    <details open class="group rounded-xl border border-zinc-200 dark:border-white/10">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4">
                            <span class="text-lg font-semibold">
                                Income Statement
                            </span>

                            <span class="text-zinc-400 transition-transform group-open:rotate-180">
                                ↓
                            </span>
                        </summary>

                        <div class="border-t border-zinc-200 p-5 dark:border-white/10">
                            <x-financial-array
                                :values="$selected->income_statement"
                                currency
                            />
                        </div>
                    </details>

                    {{-- Balance Sheet --}}
                    <details class="group rounded-xl border border-zinc-200 dark:border-white/10">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4">
                            <span class="text-lg font-semibold">
                                Balance Sheet
                            </span>

                            <span class="text-zinc-400 transition-transform group-open:rotate-180">
                                ↓
                            </span>
                        </summary>

                        <div class="border-t border-zinc-200 p-5 dark:border-white/10">
                            <x-financial-array
                                :values="$selected->balance_sheet"
                                currency
                            />
                        </div>
                    </details>

                    {{-- Cash Flow Statement --}}
                    <details class="group rounded-xl border border-zinc-200 dark:border-white/10">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4">
                            <span class="text-lg font-semibold">
                                Cash Flow Statement
                            </span>

                            <span class="text-zinc-400 transition-transform group-open:rotate-180">
                                ↓
                            </span>
                        </summary>

                        <div class="border-t border-zinc-200 p-5 dark:border-white/10 space-y-3">
                            <x-financial-array
                                :values="$selected->cash_flow_statement"
                                currency
                            />

                            <div class="flex justify-between border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700">
                                <span>Dividend paid (stored financing movement)</span>

                                <strong class="font-mono">
                                    {{ \App\Support\Presentation\FinancialValueFormatter::currency(
                                        $selected->fields['cf_equity'] ?? 0
                                    ) }}
                                </strong>
                            </div>
                        </div>
                    </details>

                    {{-- Notes --}}
                    <details class="group rounded-xl border border-zinc-200 dark:border-white/10">
                        <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4">
                            <div>
                                <span class="text-lg font-semibold">
                                    Notes and Supplementary Reports
                                </span>

                                <p class="mt-1 text-sm text-zinc-500">
                                    Every report persisted by the engine is shown below.
                                </p>
                            </div>

                            <span class="text-zinc-400 transition-transform group-open:rotate-180">
                                ↓
                            </span>
                        </summary>

                        <div class="space-y-5 border-t border-zinc-200 p-5 dark:border-white/10">

                            @forelse ($selected->notes as $report => $values)

                                <section class="space-y-2 border-t border-zinc-200 pt-4 first:border-0 first:pt-0 dark:border-zinc-700">

                                    <h3 class="font-semibold">
                                        {{ str($report)->replace('_', ' ')->title() }} report
                                    </h3>

                                    @if (is_array($values))
                                        <x-financial-array
                                            :values="$values"
                                            currency
                                        />
                                    @else
                                        <p>{{ $values }}</p>
                                    @endif

                                </section>

                            @empty

                                <p class="text-sm text-zinc-500">
                                    No supplementary reports were stored for this quarter.
                                </p>

                            @endforelse

                        </div>
                    </details>

                </section>

            @endif

        @endif

    @endif

</div>