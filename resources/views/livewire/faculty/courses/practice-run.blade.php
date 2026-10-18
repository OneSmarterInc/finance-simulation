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
                The practice run is private to you. Its firms, students and results never appear in your class, your reports or your student counts. It's removed {{ $keep_days }} days after it was last played, or sooner if you remove it.
            </flux:text>
            <div>
                <flux:button type="button" variant="primary" icon="play" wire:click="start">Start a practice run</flux:button>
            </div>
            <div wire:loading.flex wire:target="start" class="items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <flux:icon.loading class="size-4" /> Copying the course and building the warm-up quarters…
            </div>
        </flux:card>
    @else
        <flux:callout icon="lock-closed" color="zinc">
            <flux:callout.text>
                This practice run is private to you. Its firms, students and results never appear in your class, your reports or your student counts. It was made from your course's setup on {{ $run->created_at->copy()->setTimezone(\App\Support\Dates::zone())->format('j F Y') }}. If you change the setup, start again to rehearse the new version.
            </flux:callout.text>
            <flux:callout.text data-test="practice-removal">
                It's removed {{ $keep_days }} days after it was last played, so on {{ $removed_on->setTimezone(\App\Support\Dates::zone())->format('j F Y') }} unless you play it again.
                @unless ($confirmingRemove)
                    <button type="button" class="font-medium text-zinc-800 underline underline-offset-2 dark:text-white" wire:click="$set('confirmingRemove', true)">Remove it now</button>
                @endunless
            </flux:callout.text>
        </flux:callout>

        @if ($confirmingRemove)
            <div class="flex flex-col gap-3 rounded-lg border border-red-300 bg-red-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-red-500/40 dark:bg-red-500/10" data-test="practice-remove-confirm">
                <flux:text>Remove this practice run? Its firms, practice students and results are deleted for good. Your course and its team settings stay, so you can start a new run at any time.</flux:text>
                <div class="flex shrink-0 gap-2">
                    <flux:button type="button" size="sm" wire:click="$set('confirmingRemove', false)">Keep it</flux:button>
                    <flux:button type="button" size="sm" variant="danger" wire:click="remove">Remove the run</flux:button>
                </div>
            </div>
        @endif

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
            @unless ($editingTeams)
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button type="button" size="sm" icon="adjustments-horizontal" wire:click="editTeams">Change the teams</flux:button>
                    @if ($custom !== [])
                        <flux:text size="sm">{{ count($custom) === 1 ? 'One team plays' : count($custom).' teams play' }} custom settings.</flux:text>
                    @endif
                </div>
            @endunless
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
                        @if (in_array($team, $custom, true))
                            <div>
                                <span class="inline-block rounded-md bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-400/15 dark:text-violet-300" data-test="custom-badge">Custom: {{ $summaries[$team] }}</span>
                            </div>
                        @endif
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

        {{-- Layer 5: the teams' settings --}}
        @if ($editingTeams)
            @php
                $main = \App\Services\Practice\TeamSettings::MAIN_GROUPS;
                $more = \App\Services\Practice\TeamSettings::MORE_GROUPS;
                if ($evaluation === null) {
                    unset($more['Bids in the evaluation stage']);
                }
            @endphp
            <flux:card class="space-y-4" data-test="team-settings">
                <div class="space-y-1">
                    <flux:heading size="lg" level="2">Change the teams</flux:heading>
                    <flux:text class="max-w-3xl">Each team starts from its standard rules. Saving starts the run again and replays it{{ $played >= 4 ? ' from the warm-up to Quarter '.$played : '' }} with the new settings, so the table never mixes two sets of rules. The same settings always play out the same way.</flux:text>
                </div>
                <div class="grid gap-4 lg:grid-cols-3">
                    @foreach ($teams as $team)
                        <fieldset class="min-w-0 space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-white/10" wire:key="team-settings-{{ $team }}">
                            <legend class="flex items-center gap-2 px-1 font-semibold">
                                <span class="size-2.5 rounded-full {{ $dot[$team] }}" aria-hidden="true"></span>{{ $names[$team] }}
                            </legend>
                            @php
                                $showMore = $moreOpen[$team] ?? false;
                            @endphp
                            @foreach ([$main, $more] as $section => $groupsHere)
                                @continue($section === 1 && ! $showMore)
                                <div class="space-y-4">
                                    @foreach ($groupsHere as $group => $keys)
                                        <div class="space-y-3">
                                            <flux:heading level="3" size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $group }}</flux:heading>
                                            @foreach ($keys as $key)
                                                @if (in_array($key, \App\Services\Practice\TeamSettings::BOOLEANS, true))
                                                    <flux:checkbox wire:model="settings.{{ $team }}.{{ $key }}" :label="\App\Services\Practice\TeamSettings::label($key)" />
                                                @elseif (isset(\App\Services\Practice\TeamSettings::RANGES[$key]))
                                                    <flux:input type="number" step="1" wire:model="settings.{{ $team }}.{{ $key }}" :label="\App\Services\Practice\TeamSettings::label($key)" />
                                                @else
                                                    <flux:select wire:model="settings.{{ $team }}.{{ $key }}" :label="\App\Services\Practice\TeamSettings::label($key)">
                                                        @foreach (\App\Services\Practice\TeamSettings::choices($key) as $value)
                                                            <flux:select.option :value="$value">{{ \App\Services\Practice\TeamSettings::choiceLabel($key, $value) }}</flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                                @if ($section === 0)
                                    <button type="button" class="text-start text-sm font-medium text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400" wire:click="toggleMore('{{ $team }}')" aria-expanded="{{ $showMore ? 'true' : 'false' }}">
                                        {{ $showMore ? 'Fewer settings' : 'More settings: making, methods, dividend'.($evaluation !== null ? ', bids' : '') }}
                                    </button>
                                @endif
                            @endforeach
                            <flux:button type="button" size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="standardTeam('{{ $team }}')">Back to standard rules</flux:button>
                        </fieldset>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button type="button" variant="primary" wire:click="saveTeams">Save and start again</flux:button>
                    <flux:button type="button" wire:click="cancelTeams">Cancel</flux:button>
                    <div wire:loading.flex wire:target="saveTeams" class="items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300" role="status">
                        <flux:icon.loading class="size-4" /> Starting again and replaying the quarters…
                    </div>
                </div>
            </flux:card>
        @endif

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
            @if ($played >= 4 && $custom !== [])
                <flux:text size="sm" data-test="practice-leader-spans">{{ $leader_spans }}</flux:text>
            @elseif ($played >= 4)
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
