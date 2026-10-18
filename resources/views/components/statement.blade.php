@props([
    // income_statement | balance_sheet | cash_flow_statement | a note key (revenue, inventory, ppe, debt, commitments)
    'statement',
    // column heading => FirmEngineResult
    'columns' => [],
    'caption' => null,
])

@php
    use App\Support\Presentation\StatementPresenter;

    $headings = array_keys($columns);
    $rows = StatementPresenter::rows($statement, $columns);
    // Hide lines that are zero in every column; keep headings, subtotals and totals.
    // Unit counts stay even at zero (no orders left unfilled is worth seeing), but not where a result has none.
    $rows = array_values(array_filter($rows, fn ($row) => $row['type'] !== 'line' && $row['type'] !== 'memo'
        || collect($row['values'])->contains(fn ($v) => $v !== null && ($row['format'] === 'units' || abs($v) >= 0.005))));
    // Drop a heading whose whole section is now empty (the next row is another heading or the end).
    $rows = array_values(array_filter($rows, function ($row, $i) use ($rows) {
        if ($row['type'] !== 'heading') {
            return true;
        }
        $next = $rows[$i + 1] ?? null;

        return $next !== null && in_array($next['type'], ['line', 'memo'], true);
    }, ARRAY_FILTER_USE_BOTH));
@endphp

<div {{ $attributes->class('overflow-x-auto') }}>
    <table class="w-full text-sm tabular-nums" @if (count($headings) > 1) style="min-width: {{ 16 + count($headings) * 9 }}rem" @endif>
        @if ($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        <thead>
            <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600">
                <th scope="col" class="py-2 pr-4 text-left font-medium"><span class="sr-only">Line</span></th>
                @foreach ($headings as $heading)
                    <th scope="col" class="py-2 pl-4 text-right font-semibold text-zinc-700 dark:text-zinc-200">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @if ($row['type'] === 'heading')
                    <tr>
                        <th colspan="{{ count($headings) + 1 }}" scope="rowgroup"
                            class="pt-4 pb-1 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ $row['label'] }}
                        </th>
                    </tr>
                @else
                    <tr @class([
                        'border-t border-zinc-300 dark:border-zinc-600 font-semibold' => $row['type'] === 'subtotal',
                        'border-t-2 border-zinc-500 dark:border-zinc-400 font-semibold' => $row['type'] === 'total',
                        'text-zinc-500 dark:text-zinc-400' => $row['type'] === 'memo',
                    ])>
                        <th scope="row" @class([
                            'min-w-64 py-1.5 pr-4 text-left font-normal text-zinc-700 dark:text-zinc-300',
                            'pl-3' => $row['type'] === 'line' || $row['type'] === 'memo',
                            '!font-semibold text-zinc-900 dark:text-white' => in_array($row['type'], ['subtotal', 'total'], true),
                        ])>{{ $row['label'] }}</th>
                        @foreach ($row['values'] as $value)
                            <td class="py-1.5 pl-4 text-right font-mono whitespace-nowrap">
                                {{ $value === null ? '–' : StatementPresenter::format($value, $row['format']) }}
                            </td>
                        @endforeach
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</div>
