{{--
    U4 layer 1: the debrief's slides, shared by the faculty presenter and the students' read-only view.
    $built (DebriefBuilder::build), $captions (slide key => [text, by]), $show (slide keys to present, in order).
    Charts are plain SVG drawn here from the engine's figures, in the reference categorical palette.
--}}
@php
    use App\Services\Ai\ResultMeasures;

    $money = fn (?float $v) => ResultMeasures::format('cash', $v);
    $short = function (?float $v): string {
        if ($v === null) {
            return '–';
        }
        $abs = abs($v);

        return ($v < 0 ? '−' : '').'$'.($abs >= 1e6 ? number_format($abs / 1e6, 1).'M' : ($abs >= 1e3 ? number_format($abs / 1e3, 0).'K' : number_format($abs, 0)));
    };
    $firms = collect($built['firms']);
    $names = $firms->pluck('name', 'id');
    $slot = fn (string $id) => ($firms->firstWhere('id', $id)['slot'] ?? 99);
    $color = fn (string $id) => $slot($id) < 8 ? 'var(--series-'.($slot($id) + 1).')' : 'var(--series-other)';
@endphp

<style>
    .viz-root {
        --viz-surface: #ffffff; --viz-grid: #e7e5e0; --viz-axis: #8a8880; --viz-ink: #0b0b0b; --viz-ink-2: #52514e;
        --series-1: #2a78d6; --series-2: #eb6834; --series-3: #1baf7a; --series-4: #eda100;
        --series-5: #e87ba4; --series-6: #008300; --series-7: #6250d6; --series-8: #e34948; --series-other: #a3a29b;
        --viz-pos: #2a78d6; --viz-neg: #eb6834;
    }
    .dark .viz-root {
        --viz-surface: #18181b; --viz-grid: #2e2e2b; --viz-axis: #8f8e86; --viz-ink: #f0efec; --viz-ink-2: #c3c2b7;
        --series-1: #3987e5; --series-2: #d95926; --series-3: #199e70; --series-4: #c98500;
        --series-5: #d55181; --series-6: #008300; --series-7: #9085e9; --series-8: #e66767; --series-other: #6f6e68;
        --viz-pos: #3987e5; --viz-neg: #d95926;
    }
</style>

<div class="viz-root">
    @foreach ($show as $index => $key)
        @php
            $slide = $built['slides'][$key];
            $data = $slide['data'];
            $caption = $captions[$key] ?? ['text' => $slide['fallback'], 'by' => 'code'];
        @endphp
        <section x-show="i === {{ $index }}" @if ($index > 0) x-cloak @endif class="space-y-5" data-test="slide-{{ $key }}" aria-roledescription="slide" aria-label="{{ $slide['title'] }}">
            <div class="space-y-2">
                <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-300">Slide {{ $index + 1 }} of {{ count($show) }}</p>
                <h2 class="text-2xl font-semibold text-balance text-zinc-900 sm:text-3xl dark:text-white">{{ $slide['title'] }}</h2>
                <p class="max-w-3xl text-base leading-7 text-zinc-700 sm:text-lg dark:text-zinc-200" data-test="caption-{{ $key }}">{{ $caption['text'] }}</p>
            </div>

            @if ($key === 'race')
                @php
                    $quarters = $data['quarters'];
                    $all = collect($data['values'])->flatten();
                    $lo = $all->min(); $hi = $all->max(); $pad = max(1, ($hi - $lo) * 0.08);
                    $lo -= $pad; $hi += $pad;
                    $W = 960; $H = 380; $L = 76; $R = $firms->count() <= 4 ? 120 : 24; $T = 16; $B = 40;
                    $x = fn ($q) => $L + (count($quarters) > 1 ? (array_search($q, $quarters, true) / (count($quarters) - 1)) : 0.5) * ($W - $L - $R);
                    $y = fn ($v) => $T + (1 - ($v - $lo) / ($hi - $lo)) * ($H - $T - $B);
                    $ticks = collect(range(0, 4))->map(fn ($i) => $lo + $pad + ($hi - $lo - 2 * $pad) * $i / 4);
                @endphp
                <div class="overflow-x-auto">
                    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full min-w-[36rem]" role="img" aria-label="Value created by firm and quarter">
                        @foreach ($ticks as $t)
                            <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}" stroke="var(--viz-grid)" stroke-width="1" />
                            <text x="{{ $L - 8 }}" y="{{ $y($t) + 4 }}" text-anchor="end" font-size="12" fill="var(--viz-ink-2)">{{ $short($t) }}</text>
                        @endforeach
                        @foreach ($quarters as $q)
                            <text x="{{ $x($q) }}" y="{{ $H - 14 }}" text-anchor="middle" font-size="12" fill="var(--viz-ink-2)">Q{{ $q }}</text>
                        @endforeach
                        @foreach ($data['changes'] as $c)
                            <line x1="{{ $x($c['quarter']) }}" x2="{{ $x($c['quarter']) }}" y1="{{ $T }}" y2="{{ $H - $B }}" stroke="var(--viz-axis)" stroke-width="1" stroke-dasharray="3 4" />
                        @endforeach
                        @foreach ($firms as $f)
                            @php
                                $pts = collect($data['values'][$f['id']] ?? [])->map(fn ($v, $q) => round($x($q), 1).','.round($y($v), 1))->join(' ');
                            @endphp
                            <polyline points="{{ $pts }}" fill="none" stroke="{{ $color($f['id']) }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                        @endforeach
                        {{-- A marker where each firm takes the lead, ringed in the surface colour. --}}
                        @foreach ($data['changes'] as $c)
                            @php
                                $id = $data['leaders'][$c['quarter']];
                            @endphp
                            <circle cx="{{ $x($c['quarter']) }}" cy="{{ $y($data['values'][$id][$c['quarter']]) }}" r="6" fill="{{ $color($id) }}" stroke="var(--viz-surface)" stroke-width="2" />
                        @endforeach
                        {{-- Hover targets larger than the marks, with the figure to the cent. --}}
                        @foreach ($firms as $f)
                            @foreach ($data['values'][$f['id']] ?? [] as $q => $v)
                                <circle cx="{{ $x($q) }}" cy="{{ $y($v) }}" r="10" fill="transparent"><title>{{ $f['name'] }} · Quarter {{ $q }} · {{ $money($v) }}</title></circle>
                            @endforeach
                        @endforeach
                        @if ($firms->count() <= 4)
                            @foreach ($firms as $f)
                                @php
                                    $lastQ = array_key_last($data['values'][$f['id']] ?? []);
                                @endphp
                                @if ($lastQ !== null)
                                    <text x="{{ $x($lastQ) + 8 }}" y="{{ $y($data['values'][$f['id']][$lastQ]) + 4 }}" font-size="13" fill="var(--viz-ink)">{{ $f['name'] }}</text>
                                @endif
                            @endforeach
                        @endif
                    </svg>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                    @foreach ($firms as $f)
                        <span class="inline-flex items-center gap-2"><span class="inline-block h-0.5 w-5 rounded" style="background: {{ $color($f['id']) }}"></span>{{ $f['name'] }}</span>
                    @endforeach
                    @if ($data['changes'] !== [])
                        <span class="inline-flex items-center gap-2 text-zinc-500"><span class="inline-block h-4 border-l border-dashed border-zinc-400"></span>Lead changed hands</span>
                    @endif
                </div>
                <details class="text-sm">
                    <summary class="cursor-pointer text-zinc-600 dark:text-zinc-400">Show the figures as a table</summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full tabular-nums">
                            <thead><tr class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-white/10"><th class="py-1 pe-3 text-left font-medium">Firm</th>@foreach ($quarters as $q)<th class="px-2 py-1 text-right font-medium">Q{{ $q }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($firms as $f)
                                    <tr class="border-b border-zinc-100 dark:border-white/5"><th class="py-1 pe-3 text-left font-medium">{{ $f['name'] }}</th>@foreach ($quarters as $q)<td @class(['px-2 py-1 text-right', 'font-semibold' => ($data['leaders'][$q] ?? null) === $f['id']])>{{ $money($data['values'][$f['id']][$q] ?? null) }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @elseif ($key === 'profit_cash')
                @php
                    $vals = [$data['net_income'], $data['operating_cash_flow']];
                    $lo = min(0, ...$vals); $hi = max(0, ...$vals); $span = max(1, $hi - $lo);
                    $bx = fn ($v) => 20 + ($v - $lo) / $span * ($lo < 0 && $hi <= 0 ? 700 : 760);
                    $lines = collect($data['lines']);
                    $lmax = max(1, $lines->max(fn ($l) => abs($l['value'])) ?? 1);
                @endphp
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ $data['firm'] }}, Quarter {{ $data['quarter'] }}</p>
                <svg viewBox="0 0 960 168" class="w-full" role="img" aria-label="Net income against net cash from operating activities">
                    @foreach ([['Net income', $data['net_income'], 'var(--series-1)'], ['Net cash from operating activities', $data['operating_cash_flow'], 'var(--series-3)']] as $row => [$label, $v, $fill])
                        @php
                            $x0 = $bx(min(0, $v)); $x1 = $bx(max(0, $v)); $yy = 26 + $row * 76;
                            // The value sits beyond the bar's end, or on the far side of zero for a bar that runs left.
                            $tx = $v >= 0 ? min($x1 + 8, 952) : $bx(0) + 8;
                        @endphp
                        <text x="20" y="{{ $yy - 8 }}" font-size="14" fill="var(--viz-ink-2)">{{ $label }}</text>
                        <rect x="{{ $x0 }}" y="{{ $yy }}" width="{{ max(1, $x1 - $x0) }}" height="34" rx="4" fill="{{ $fill }}"><title>{{ $label }} · {{ $money($v) }}</title></rect>
                        <text x="{{ $tx }}" y="{{ $yy + 22 }}" text-anchor="{{ $v >= 0 && $x1 + 8 > 800 ? 'end' : 'start' }}" font-size="15" font-weight="600" fill="var(--viz-ink)">{{ $money($v) }}</text>
                    @endforeach
                    <line x1="{{ $bx(0) }}" x2="{{ $bx(0) }}" y1="20" y2="166" stroke="var(--viz-axis)" stroke-width="1" />
                </svg>
                <div class="space-y-2">
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">What lies between them on the cash flow statement</p>
                    <table class="w-full text-sm tabular-nums">
                        <tbody>
                            @foreach ($lines as $l)
                                <tr class="border-b border-zinc-100 dark:border-white/5">
                                    <th class="py-1.5 pe-3 text-left font-normal">{{ $l['label'] }}</th>
                                    <td class="w-28 py-1.5 pe-3 text-right whitespace-nowrap">{{ $money($l['value']) }}</td>
                                    <td class="w-1/3 py-1.5" aria-hidden="true">
                                        <div class="relative h-2">
                                            <div class="absolute top-0 h-2 rounded" style="left: {{ $l['value'] >= 0 ? 50 : 50 - abs($l['value']) / $lmax * 50 }}%; width: {{ abs($l['value']) / $lmax * 50 }}%; background: {{ $l['value'] >= 0 ? 'var(--viz-pos)' : 'var(--viz-neg)' }}"></div>
                                            <div class="absolute top-[-2px] left-1/2 h-3 border-l border-zinc-300 dark:border-zinc-600"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Blue lines add to cash from operations; orange lines take away from it.</p>
                </div>
            @elseif ($key === 'methods')
                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600">
                                <th class="py-2 pe-3 text-left font-medium">Firm and change</th>
                                <th class="px-2 py-2 text-right font-medium">Net income, new methods</th>
                                <th class="px-2 py-2 text-right font-medium">Net income, old methods</th>
                                <th class="px-2 py-2 text-right font-medium">Cash from operations, new</th>
                                <th class="px-2 py-2 text-right font-medium">Cash from operations, old</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['rows'] as $r)
                                <tr class="border-b border-zinc-100 align-top dark:border-white/5">
                                    <th class="py-2 pe-3 text-left font-normal"><span class="font-medium">{{ $r['firm'] }}</span>, from Quarter {{ $r['quarter'] }}<span class="block text-xs text-zinc-500">{{ $r['change'] }}</span></th>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $money($r['net_income']) }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $money($r['net_income_old']) }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $money($r['operating_cash_flow']) }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $money($r['operating_cash_flow_old']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">"Old methods" is the same quarter played again by the engine with the same decisions and the methods the firm used before. Nothing is estimated.</p>
                <p class="text-xs"><a href="{{ route('guide') }}#{{ \App\Support\Explainers\Explainers::METHODS }}" class="font-medium text-emerald-700 underline underline-offset-2 hover:text-emerald-800 dark:text-emerald-300" data-test="explainer-link">See one method change move all four statements, step by step</a></p>
            @elseif ($key === 'reveal')
                @php
                    $rows = collect($data['rows']);
                    $vmax = max(1, $rows->flatMap(fn ($r) => [$r['consultant'], $r['price'] ?? 0, $r['true'], $r['closest_figure'] ?? 0])->max()) * 1.05;
                    $rx = fn ($v) => 150 + max(0, $v) / $vmax * 780;
                    $RH = 44;
                @endphp
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <span class="inline-flex items-center gap-2"><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="5" fill="none" stroke="var(--series-2)" stroke-width="2"/></svg>Consultant's value of 51%</span>
                    <span class="inline-flex items-center gap-2"><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="5" fill="var(--series-1)"/></svg>Price paid</span>
                    <span class="inline-flex items-center gap-2"><svg width="14" height="14" aria-hidden="true"><rect x="5" y="0" width="4" height="14" rx="1" fill="var(--series-3)"/></svg>True worth of 51%</span>
                </div>
                <div class="overflow-x-auto">
                    <svg viewBox="0 0 960 {{ 24 + $rows->count() * $RH }}" class="w-full min-w-[36rem]" role="img" aria-label="Consultant's value, price paid and true worth for each firm sold">
                        @foreach ($rows as $n => $r)
                            @php
                                $cy = 22 + $n * $RH;
                                $pts = array_filter([$r['consultant'], $r['price'], $r['true']], fn ($v) => $v !== null);
                            @endphp
                            <text x="140" y="{{ $cy + 5 }}" text-anchor="end" font-size="14" fill="var(--viz-ink)">{{ $r['target'] }}</text>
                            <line x1="{{ $rx(min($pts)) }}" x2="{{ $rx(max($pts)) }}" y1="{{ $cy }}" y2="{{ $cy }}" stroke="var(--viz-grid)" stroke-width="4" stroke-linecap="round" />
                            <rect x="{{ $rx($r['true']) - 2 }}" y="{{ $cy - 12 }}" width="4" height="24" rx="1" fill="var(--series-3)"><title>{{ $r['target'] }} · true worth of 51% · {{ $money($r['true']) }}</title></rect>
                            <circle cx="{{ $rx($r['consultant']) }}" cy="{{ $cy }}" r="7" fill="var(--viz-surface)" stroke="var(--series-2)" stroke-width="2"><title>{{ $r['target'] }} · consultant's value of 51% · {{ $money($r['consultant']) }}</title></circle>
                            @if ($r['price'] !== null)
                                <circle cx="{{ $rx($r['price']) }}" cy="{{ $cy }}" r="7" fill="var(--series-1)" stroke="var(--viz-surface)" stroke-width="2"><title>{{ $r['target'] }} · price paid by {{ $r['winner'] }} · {{ $money($r['price']) }}</title></circle>
                            @endif
                        @endforeach
                    </svg>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600">
                                <th class="py-2 pe-3 text-left font-medium">Firm</th>
                                <th class="px-2 py-2 text-right font-medium">Consultant's 51%</th>
                                <th class="px-2 py-2 text-right font-medium">Price paid</th>
                                <th class="px-2 py-2 text-right font-medium">True worth of 51%</th>
                                <th class="px-2 py-2 text-right font-medium">Paid above (below) worth</th>
                                <th class="px-2 py-2 text-right font-medium">Closest team figure</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="border-b border-zinc-100 dark:border-white/5">
                                    <th class="py-2 pe-3 text-left font-medium">{{ $r['target'] }}</th>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $money($r['consultant']) }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $r['price'] === null ? 'Not sold' : $money($r['price']) }}<span class="block text-xs text-zinc-500">{{ $r['winner'] }}</span></td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $money($r['true']) }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $r['difference'] === null ? '–' : ($r['difference'] >= 0 ? $money($r['difference']) : '('.$money(-$r['difference']).')') }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $r['closest_team'] ? $money($r['closest_figure']) : '–' }}<span class="block text-xs text-zinc-500">{{ $r['closest_team'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($caption['by'] === 'claude')
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Words by Claude, from the figures on this slide and checked against them. Every figure comes from the course's stored results.</p>
            @endif
        </section>
    @endforeach
</div>
