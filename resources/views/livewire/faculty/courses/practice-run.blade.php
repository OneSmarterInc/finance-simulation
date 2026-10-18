@php
    $dot = ['careful' => 'bg-emerald-700 dark:bg-emerald-400', 'average' => 'bg-sky-800 dark:bg-sky-300', 'careless' => 'bg-amber-700 dark:bg-amber-400'];
    $ink = ['careful' => 'text-emerald-700 dark:text-emerald-400', 'average' => 'text-sky-800 dark:text-sky-300', 'careless' => 'text-amber-700 dark:text-amber-400'];
    $millions = fn (float $v): string => ($v < 0 ? '−' : '').'$'.number_format(abs($v) / 1e6, 1).'M';
    $units = fn (float $v): string => number_format($v);
    $thousands = fn (float $v): string => number_format($v / 1e3, 1);
    $measures = [
        ['Value created', 'value', $millions],
        ['Net income', 'net_income', $millions],
        ['Cash at quarter end', 'cash', $millions],
        ['Units customers ordered', 'ordered', $units],
        ['Units sold', 'sold', $units],
        ['Orders not filled', 'short', $units],
        ['Units left in stock', 'stock', $units],
        ['Inventory', 'inventory', $millions],
        ['Warranty reserve ($ thousands)', 'warranty', $thousands],
    ];
    $lead = $played >= 4 ? ($leaders[$played] ?? null) : null;
@endphp

<div class="flexee-page">
    <x-page-header :eyebrow="$course->name.' · '.\App\Support\Product::name($course)" title="Practice run"
        description="Three reference teams play your course with the class's engine, so you can see how it unfolds before you teach it.">
        @if ($run)
            <x-slot:actions>
                <flux:badge size="lg" color="zinc">Played to Quarter {{ $played }} of {{ $last }}</flux:badge>
            </x-slot:actions>
        @endif
    </x-page-header>

    @error('practice')
        <flux:callout variant="danger" icon="exclamation-triangle" heading="The practice run stopped" :text="$message" />
    @enderror

    @if (! $can_rehearse)
        <flux:callout icon="information-circle" heading="This course runs on the earlier engine"
            text="Practice runs need a course on the current engine, which has the market demand the reference teams plan from. Courses created before October 2026 stay on the earlier engine until they end." />
    @elseif (! $run)
        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">Rehearse this course</flux:heading>
            <flux:text class="max-w-3xl">
                A practice run copies your course as it is set up now: the same schedule, the same decision areas opening in the same quarters, and the same starting methods. Three reference teams play it, Careful, Average and Careless. You can stop at any quarter and look at the results.
            </flux:text>
            <flux:text class="max-w-3xl">
                The practice run is private to you. Its firms, students and results never appear in your class, your reports or your student counts.
            </flux:text>
            <div>
                <flux:button type="button" variant="primary" icon="play" wire:click="start">Start a practice run</flux:button>
            </div>
            <div wire:loading.flex wire:target="start" class="items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <flux:icon.loading class="size-4" /> Copying the course and building the warm-up quarters…
            </div>
        </flux:card>
    @else
        <flux:callout icon="lock-closed" color="zinc"
            text="This practice run is private to you. Its firms, students and results never appear in your class, your reports or your student counts. It was made from your course's setup on {{ $run->created_at->copy()->setTimezone(\App\Support\Dates::zone())->format('j F Y') }}. If you change the setup, start again to rehearse the new version." />

        {{-- Play the course --}}
        <flux:card class="space-y-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <flux:heading size="lg" level="2">Play the course</flux:heading>
                <flux:text size="sm">Quarters 1 to 3 are the warm-up. Teams decide from Quarter 4.</flux:text>
            </div>

            @if ($confirmingStartAgain)
                <div class="flex flex-col gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/40 dark:bg-amber-500/10">
                    <flux:text>Start again removes this run and makes a fresh copy of your course as it is set up now. Every run of the same setup plays out the same way.</flux:text>
                    <div class="flex shrink-0 gap-2">
                        <flux:button type="button" size="sm" wire:click="$set('confirmingStartAgain', false)">Keep this run</flux:button>
                        <flux:button type="button" size="sm" variant="danger" wire:click="startAgain">Start again</flux:button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap items-end gap-2">
                    @if ($played < $last)
                        <flux:field class="w-40">
                            <flux:label>Play to</flux:label>
                            <flux:select wire:model="target">
                                @for ($q = $played + 1; $q <= $last; $q++)
                                    <flux:select.option :value="$q">Quarter {{ $q }}</flux:select.option>
                                @endfor
                            </flux:select>
                        </flux:field>
                        <flux:button type="button" variant="primary" wire:click="play">Play</flux:button>
                        <flux:button type="button" wire:click="playToEnd">Play to the end</flux:button>
                    @else
                        <flux:text>The run has reached the last quarter.</flux:text>
                    @endif
                    <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="$set('confirmingStartAgain', true)">Start again</flux:button>
                </div>
            @endif
            <div wire:loading.flex wire:target="play,playToEnd,startAgain" class="items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300" role="status">
                <flux:icon.loading class="size-4" /> The teams are deciding and the quarters are closing…
            </div>
        </flux:card>

        {{-- The reference teams --}}
        <section class="space-y-3" aria-labelledby="teams-heading">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <flux:heading size="lg" level="2" id="teams-heading">The reference teams</flux:heading>
                <flux:text size="sm" class="max-w-xl sm:text-right">View as a team to open its student pages as its students see them. The next quarter is open, so its decision screen is there too. The next Play decides it by the team's rules, whatever was typed in.</flux:text>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ($teams as $team)
                    @php
                        $row = $board[$team][$played] ?? null;
                    @endphp
                    <flux:card class="flex min-w-0 flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-2.5 rounded-full {{ $dot[$team] }}" aria-hidden="true"></span>
                            <span class="font-semibold">{{ $names[$team] }}</span>
                            @if ($lead === $team)
                                <flux:badge size="sm" color="emerald">Leading</flux:badge>
                            @endif
                        </div>
                        @if ($row)
                            <div class="text-2xl font-semibold tabular-nums">{{ $millions($row['value']) }}</div>
                            <flux:text size="sm">value created by Quarter {{ $played }}</flux:text>
                        @endif
                        <flux:text size="sm" class="mt-1">{{ $rules[$team] }}</flux:text>
                        <form method="POST" action="{{ route('faculty.courses.practice.view-as', [$course, $team]) }}" class="mt-auto pt-2">
                            @csrf
                            <flux:button type="submit" size="sm" icon="eye" class="w-full">View as {{ $names[$team] }}</flux:button>
                        </form>
                    </flux:card>
                @endforeach
            </div>
        </section>

        {{-- Quarter by quarter --}}
        <flux:card class="space-y-3">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <flux:heading size="lg" level="2">Quarter by quarter</flux:heading>
                <flux:text size="sm">Value created is equity plus dividends paid from Quarter 4.</flux:text>
            </div>
            <div class="overflow-x-auto" x-data x-init="$el.scrollLeft = $el.scrollWidth">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left dark:border-white/10">
                            <th scope="col" class="sticky left-0 bg-white py-2 pe-4 font-medium dark:bg-zinc-900">Team and measure</th>
                            @for ($q = 1; $q <= $played; $q++)
                                <th scope="col" class="px-3 py-2 text-right font-medium">
                                    Q{{ $q }}
                                    @if ($q <= 3)
                                        <span class="block text-xs font-normal text-zinc-500">warm-up</span>
                                    @endif
                                </th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teams as $team)
                            <tr>
                                <th scope="rowgroup" class="sticky left-0 bg-white pt-4 pb-1 text-left font-semibold whitespace-nowrap dark:bg-zinc-900">
                                    <span class="me-1 inline-block size-2.5 rounded-full {{ $dot[$team] }}" aria-hidden="true"></span>
                                    {{ $names[$team] }}
                                </th>
                                <td colspan="{{ $played }}"></td>
                            </tr>
                            @foreach ($measures as [$label, $key, $format])
                                <tr class="border-b border-zinc-100 dark:border-white/5">
                                    <th scope="row" class="sticky left-0 w-36 min-w-36 bg-white py-1.5 pe-3 text-left font-normal text-zinc-600 sm:w-auto sm:whitespace-nowrap dark:bg-zinc-900 dark:text-zinc-300">{{ $label }}</th>
                                    @for ($q = 1; $q <= $played; $q++)
                                        <td class="whitespace-nowrap px-3 py-1.5 text-right {{ $q <= 3 ? 'text-zinc-500' : '' }}">{{ isset($board[$team][$q]) ? $format($board[$team][$q][$key]) : '' }}</td>
                                    @endfor
                                </tr>
                            @endforeach
                        @endforeach
                        <tr>
                            <th scope="row" class="sticky left-0 bg-white pt-4 pe-4 text-left font-semibold dark:bg-zinc-900">Leading on value created</th>
                            @for ($q = 1; $q <= $played; $q++)
                                <td class="px-3 pt-4 text-right font-semibold {{ isset($leaders[$q]) ? $ink[$leaders[$q]] : 'text-zinc-400' }}">
                                    {{ isset($leaders[$q]) ? str_replace(' team', '', $names[$leaders[$q]]) : '–' }}
                                </td>
                            @endfor
                        </tr>
                    </tbody>
                </table>
            </div>
            @if ($played >= 4)
                @if ($careful_leads)
                    <flux:text size="sm"><span class="font-semibold {{ $ink['careful'] }}">Careful leads in every quarter from 4 to {{ $played }}</span>, as the reference teams should.</flux:text>
                @else
                    <flux:text size="sm"><span class="font-semibold text-red-700 dark:text-red-400">Careful doesn't lead every quarter.</span> Please report this. It means the course's settings need a look.</flux:text>
                @endif
            @endif
        </flux:card>
        {{-- The evaluation stage (+ courses) --}}
        @if ($evaluation)
            <flux:card class="space-y-4" data-test="practice-evaluation">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">Evaluation stage</flux:heading>
                    @if ($evaluation['settled'])
                        <flux:badge color="zinc">Settled after Quarter {{ $last }}</flux:badge>
                    @elseif ($evaluation['open'])
                        <flux:badge color="amber">Open{{ $evaluation['offers'] !== [] ? ': offers placed' : '' }}</flux:badge>
                    @else
                        <flux:badge color="amber">Opens with Quarter {{ $evaluation['opens_with'] }}</flux:badge>
                    @endif
                </div>

                @if (! $evaluation['open'])
                    <flux:text>Play to Quarter {{ $evaluation['opens_with'] - 1 }} to open the stage and see the consultant reports and each team's true worth. The teams bid in Quarter {{ $evaluation['opens_with'] }}.</flux:text>
                @else
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="min-w-0 space-y-2">
                            <flux:heading level="3">What each firm is worth</flux:heading>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm tabular-nums">
                                    <thead>
                                        <tr class="border-b border-zinc-200 text-left dark:border-white/10">
                                            <th scope="col" class="py-2 pe-3 font-medium">Firm</th>
                                            <th scope="col" class="px-3 py-2 text-right font-medium">Consultant value</th>
                                            <th scope="col" class="px-3 py-2 text-right font-medium">True worth</th>
                                            <th scope="col" class="ps-3 py-2 text-right font-medium">{{ $stake }}% stake at true worth</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($evaluation['firms'] as $firm)
                                            <tr class="border-b border-zinc-100 dark:border-white/5">
                                                <th scope="row" class="py-1.5 pe-3 text-left font-normal whitespace-nowrap">
                                                    <span class="me-1 inline-block size-2.5 rounded-full {{ $dot[$firm['team']] }}" aria-hidden="true"></span>{{ $firm['name'] }}
                                                </th>
                                                <td class="px-3 py-1.5 text-right">{{ $millions($firm['consultant']) }}</td>
                                                <td class="px-3 py-1.5 text-right">{{ $millions($firm['true_worth']) }}</td>
                                                <td class="ps-3 py-1.5 text-right">{{ $millions($firm['true_stake']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <flux:text size="sm">The consultant values each firm from its reported operating income. True worth is based on its operating cash. Students see only the consultant's figure, so a team that reads past reported income can bid closer to what a firm is really worth.</flux:text>
                        </div>

                        <div class="min-w-0 space-y-2">
                            <flux:heading level="3">Offers for a {{ $stake }}% stake</flux:heading>
                            @if ($evaluation['offers'] === [])
                                <flux:text>The teams place their offers when Quarter {{ $evaluation['opens_with'] }} is played.</flux:text>
                            @else
                                @php
                                    $won = collect($evaluation['results'])->keyBy('target');
                                    $previousTarget = null;
                                @endphp
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm tabular-nums">
                                        <thead>
                                            <tr class="border-b border-zinc-200 text-left dark:border-white/10">
                                                <th scope="col" class="py-2 pe-3 font-medium">For</th>
                                                <th scope="col" class="px-3 py-2 font-medium whitespace-nowrap">Offer from</th>
                                                <th scope="col" class="px-3 py-2 text-right font-medium">Amount</th>
                                                @if ($evaluation['settled'])
                                                    <th scope="col" class="ps-3 py-2 font-medium">Result</th>
                                                @endif
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($evaluation['offers'] as $offer)
                                                @php
                                                    $result = $won[$offer['target']] ?? null;
                                                    $isWinner = $result && $result['winner'] === $offer['bidder'];
                                                @endphp
                                                <tr class="{{ $previousTarget !== $offer['target'] ? 'border-t border-zinc-200 dark:border-white/10' : '' }}">
                                                    <th scope="row" class="py-1.5 pe-3 text-left font-normal whitespace-nowrap">{{ $previousTarget !== $offer['target'] ? str_replace(' team', '', $offer['target_name']) : '' }}</th>
                                                    <td class="px-3 py-1.5 whitespace-nowrap"><span class="font-medium {{ $ink[$offer['bidder']] }}">{{ str_replace(' team', '', $offer['bidder_name']) }}</span></td>
                                                    <td class="px-3 py-1.5 text-right whitespace-nowrap">
                                                        {{ $millions($offer['amount']) }}
                                                        @if ($offer['capped'])
                                                            <span class="block text-xs text-zinc-500">all it could fund</span>
                                                        @endif
                                                    </td>
                                                    @if ($evaluation['settled'])
                                                        <td class="min-w-44 ps-3 py-1.5">
                                                            @if ($isWinner)
                                                                Won, paid {{ $millions(abs($result['difference'])) }} {{ $result['difference'] >= 0 ? 'over' : 'under' }} true worth
                                                            @else
                                                                <span class="text-zinc-500">Outbid</span>
                                                            @endif
                                                        </td>
                                                    @endif
                                                </tr>
                                                @php
                                                    $previousTarget = $offer['target'];
                                                @endphp
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                            <ul class="space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                                @foreach ($teams as $team)
                                    <li><span class="font-medium {{ $ink[$team] }}">{{ str_replace(' team', '', $names[$team]) }}</span> {{ lcfirst($bid_rules[$team]) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    @if ($evaluation['settled'])
                        <div class="space-y-1 border-t border-zinc-200 pt-3 dark:border-white/10" data-test="practice-reveal">
                            <flux:heading level="3">The reveal</flux:heading>
                            @foreach ($evaluation['results'] as $result)
                                <flux:text>
                                    @if ($result['winner'] === null)
                                        Nobody offered for {{ $result['target_name'] }}.
                                    @else
                                        <span class="font-medium {{ $ink[$result['winner']] }}">{{ $result['winner_name'] }}</span> won {{ $result['target_name'] }} for {{ $millions($result['price']) }}, {{ $millions(abs($result['difference'])) }} {{ $result['difference'] >= 0 ? 'more' : 'less' }} than the stake was truly worth.
                                    @endif
                                </flux:text>
                            @endforeach
                        </div>
                    @elseif ($evaluation['offers'] !== [])
                        <flux:text size="sm">The offers stay sealed until the last quarter closes. Play to Quarter {{ $last }} to settle the auction and see who paid what against true worth.</flux:text>
                    @endif
                @endif
            </flux:card>
        @endif
    @endif
</div>
