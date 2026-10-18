{{-- Live walkthrough L16: one measure, every firm, every quarter, with the best firm marked each quarter from Quarter 4. --}}
<div class="space-y-4" data-test="results-compare">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <flux:heading size="lg" level="2">All firms, every quarter</flux:heading>
        <div class="w-full sm:w-80">
            <flux:select wire:model.live="measure" label="Measure">
                @foreach ($measures as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm tabular-nums" style="min-width: {{ 12 + $quarters->count() * 8 }}rem">
            <thead>
                <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600">
                    <th scope="col" class="py-2 pe-4 text-left font-medium">Firm</th>
                    @foreach ($quarters as $q)
                        <th scope="col" class="px-3 py-2 text-right font-semibold text-zinc-700 dark:text-zinc-200">
                            Q{{ $q }}
                            @if ($q <= 3)
                                <span class="block text-xs font-normal text-zinc-500">warm-up</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($firms as $firm)
                    <tr class="border-b border-zinc-100 dark:border-white/5">
                        <th scope="row" class="py-2 pe-4 text-left font-medium whitespace-nowrap">{{ $firm->name }}</th>
                        @foreach ($quarters as $q)
                            @php
                                $v = $compare[$firm->id][$q] ?? null;
                                $isBest = ($best[$q] ?? null) === $firm->id;
                            @endphp
                            <td @class(['px-3 py-2 text-right whitespace-nowrap', 'text-zinc-500' => $q <= 3, 'font-semibold text-emerald-700 dark:text-emerald-400' => $isBest])>
                                {{ $v === null ? '–' : \App\Services\Ai\ResultMeasures::format($measure, $v) }}
                                @if ($isBest)
                                    <span class="sr-only">(best this quarter)</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                <tr>
                    <th scope="row" class="pt-3 pe-4 text-left font-semibold">Best this quarter</th>
                    @foreach ($quarters as $q)
                        <td class="px-3 pt-3 text-right font-semibold whitespace-nowrap text-emerald-700 dark:text-emerald-400">
                            {{ isset($best[$q]) ? $firms->firstWhere('id', $best[$q])?->name : '–' }}
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>
    <flux:text size="sm">
        @if (in_array($measure, ['orders_not_filled', 'units_in_stock'], true))
            Fewer is better here, so the firm with the fewest is marked.
        @else
            The firm with the highest figure is marked.
        @endif
        Firms are marked from Quarter 4, when teams take over. Value created is equity plus dividends paid from Quarter 4.
    </flux:text>
</div>
