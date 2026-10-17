@props(['values', 'currency' => false, 'nonMonetaryKeys' => []])

<dl class="min-w-0 divide-y divide-zinc-200 text-sm dark:divide-white/10">
    @foreach($values as $key => $value)
        @continue($key === 'quarter_number')

        @if(is_array($value))
            <div class="py-3">
                <dt class="mb-2 text-xs font-semibold uppercase tracking-normal text-zinc-700 dark:text-zinc-300">{{ \App\Support\Presentation\FinancialValueFormatter::label($key) }}</dt>
                <dd class="border-l-2 border-emerald-600/30 pl-4 dark:border-emerald-400/30">
                    <x-financial-array :values="$value" :currency="$currency" :non-monetary-keys="$nonMonetaryKeys" />
                </dd>
            </div>
        @else
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(7rem,auto)] items-baseline gap-4 py-2.5">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ \App\Support\Presentation\FinancialValueFormatter::label($key) }}</dt>
                <dd class="break-words text-right font-mono text-sm font-medium tabular-nums text-zinc-950 dark:text-white">
                    @if(is_numeric($value))
                        @php($format = \App\Support\Presentation\FinancialValueFormatter::formatFor((string) $key))
                        {{ $format !== 'currency'
                            ? \App\Support\Presentation\StatementPresenter::format((float) $value, $format)
                            : ($currency && ! in_array($key, $nonMonetaryKeys, true)
                                ? \App\Support\Presentation\FinancialValueFormatter::currency($value)
                                : \App\Support\Presentation\FinancialValueFormatter::number($value)) }}
                    @elseif(is_bool($value))
                        {{ $value ? 'Yes' : 'No' }}
                    @else
                        {{ $value ?? 'Not reported' }}
                    @endif
                </dd>
            </div>
        @endif
    @endforeach
</dl>
