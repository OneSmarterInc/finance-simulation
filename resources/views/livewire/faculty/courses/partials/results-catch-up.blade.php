{{-- U10 (PJ1 to PJ3): can anyone catch the leader? Real quarters, then projected ones, played in memory and never saved. --}}
@php
    $money = fn (?float $v) => $v === null ? '–' : \App\Services\Ai\ResultMeasures::format('cash', $v);
    $short = function (?float $v): string {
        if ($v === null) {
            return '';
        }
        $abs = abs($v);

        return ($v < 0 ? '−' : '').'$'.($abs >= 1e6 ? number_format($abs / 1e6, 1).'M' : ($abs >= 1e3 ? number_format($abs / 1e3, 0).'K' : number_format($abs, 0)));
    };
    // From the quarter the projection starts from (the last one with results) to the end; the comparison view has the history.
    $all = $projection ? collect($projection['firms'])->flatMap(fn ($f) => array_keys($f['values']))->unique()->sort()->values() : collect();
    $firstProjected = $projection ? collect($projection['firms'])->flatMap(fn ($f) => $f['projected'])->min() : null;
    $columns = $all->filter(fn ($q) => $firstProjected ? $q >= $firstProjected - 1 : $q >= ($all->contains(4) ? 4 : 3))->values();
    $projectedQuarters = $projection ? collect($projection['firms'])->flatMap(fn ($f) => $f['projected'])->unique()->all() : [];
    $names = $projection ? collect($projection['firms'])->pluck('name', 'id') : collect();
@endphp
<div class="space-y-4" data-test="results-catch-up">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="lg" level="2">Can anyone catch up?</flux:heading>
            <flux:text size="sm" class="max-w-2xl">
                Where each firm stands now, then the rest of the course played from there. Choose how each firm plays from here. "All firms, every quarter" has the quarters before.
            </flux:text>
        </div>
        <div class="w-full sm:w-72">
            <flux:select wire:model.live="projectMeasure" label="Measure">
                @foreach ($projectMeasures as $key => $label)
                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($projection)
        <div class="rounded-md border border-sky-200 bg-sky-50/70 px-4 py-3 text-sm font-medium text-zinc-900 dark:border-sky-400/20 dark:bg-sky-400/5 dark:text-white" data-test="catch-up-answer" role="status">
            {{ $projection['answer'] }}
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm tabular-nums" style="min-width: {{ 13 + $columns->count() * 5.5 }}rem">
                <thead>
                    <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600">
                        <th scope="col" class="py-2 pe-4 text-left font-medium">Firm, and how it plays from here</th>
                        @foreach ($columns as $q)
                            @php
                                $isProjected = in_array($q, $projectedQuarters, true);
                            @endphp
                            <th scope="col" @class(['px-2 py-2 text-right font-semibold text-zinc-700 dark:text-zinc-200', 'bg-sky-50 dark:bg-sky-400/10' => $isProjected])>
                                Q{{ $q }}
                                <span class="block text-xs font-normal text-zinc-500">{{ $isProjected ? 'projected' : ($q <= 3 ? 'warm-up' : 'actual') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projection['firms'] as $row)
                        <tr class="border-b border-zinc-100 align-top dark:border-white/5" wire:key="catch-up-{{ $row['id'] }}">
                            <th scope="row" class="py-2 pe-4 text-left font-medium">
                                <span class="block whitespace-nowrap">{{ $row['name'] }}</span>
                                @if (count($behaviourOptions) > 1)
                                    <select wire:model.live="behaviours.{{ $row['id'] }}" aria-label="How {{ $row['name'] }} plays from here"
                                        class="mt-1 w-44 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-normal dark:border-white/10 dark:bg-zinc-800">
                                        @foreach ($behaviourOptions as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="block text-xs font-normal text-zinc-500">{{ reset($behaviourOptions) }}</span>
                                @endif
                            </th>
                            @foreach ($columns as $q)
                                @php
                                    $v = $row['values'][$q] ?? null;
                                    $leads = ($projection['leaders'][$q] ?? null) === $row['id'];
                                    $gap = $projection['gaps'][$row['id']][$q] ?? null;
                                    $isProjected = in_array($q, $row['projected'], true);
                                @endphp
                                <td @class([
                                    'px-2 py-2 text-right whitespace-nowrap',
                                    'bg-sky-50 italic dark:bg-sky-400/10' => $isProjected,
                                    'text-zinc-500' => $q <= 3,
                                    'font-semibold text-emerald-700 dark:text-emerald-400' => $leads && $q >= 4,
                                ])>
                                    <span title="{{ $v === null ? '' : \App\Services\Ai\ResultMeasures::format('cash', $v) }}">{{ $v === null ? '–' : $short($v) }}</span>
                                    @if ($q >= 4 && $v !== null)
                                        <span class="block text-xs font-normal not-italic text-zinc-500 dark:text-zinc-400">
                                            {{ $leads ? 'leads' : $short($gap).' behind' }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:text size="sm" class="max-w-3xl">
            Figures are rounded; point at one to see it to the cent. Shaded quarters are a projection. Each firm starts from its real position at the last closed quarter, and the engine plays the rest of the course with the course's own market, closing each quarter as the real close does. A firm repeating its latest decisions uses the decisions it has submitted for the open quarter, if any. Nothing is saved, and students never see this.
            @if ($course->cf_plus_enabled)
                Offers and settlement in the evaluation stage don't change a firm's statements, so they don't change the projection. The consultant's value for each quarter uses that firm's last three quarters of operating income.
            @endif
            @if ($projection['note'])
                {{ $projection['note'] }}
            @endif
        </flux:text>
    @endif
</div>
