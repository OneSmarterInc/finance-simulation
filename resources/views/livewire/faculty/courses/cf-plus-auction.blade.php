<div class="flexee-page">
    <x-page-header
        :eyebrow="$course->name"
        title="Evaluation stage"
        description="Teams study each other's published statements, receive a consultant's valuation of each rival and place sealed offers. You settle the stage once the last quarter closes, and students then see the reveal."
    />

    @if($statusMessage)
        <flux:callout
            variant="success"
            icon="check-circle"
            :heading="$statusMessage"
            role="status"
        />
    @endif

    @if($errors->any())
        <flux:callout
            variant="danger"
            icon="exclamation-triangle"
            :heading="$errors->first()"
            role="alert"
        />
    @endif

    @if($settled->isEmpty() && ! $stageOpen)

        {{-- Item 63: before the stage opens, a plain page instead of a live auction --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg" level="2">The evaluation stage hasn't opened yet</flux:heading>
            <flux:text>{{ $status->evaluationOpensText() }}</flux:text>
            <flux:text>
                When it opens, every firm's membership freezes and every firm's published statements and notes become visible to its rivals.
                Each team then sees, for every rival, the statements, a consultant's valuation and the most it could fund, and saves a sealed offer for a controlling stake.
                You'll see here how many offers each firm has received, never the amounts.
            </flux:text>
            {{-- L20: courses that can be rehearsed can see the stage played out. --}}
            @if ($course->engineVersion() === 2 && ! $course->is_practice)
                <flux:text data-test="evaluation-practice-link">
                    See it played out: the practice run takes three reference teams through this stage, offers and reveal included.
                    <flux:link :href="route('faculty.courses.practice', $course)" wire:navigate>Open the practice run</flux:link>
                </flux:text>
            @endif
        </flux:card>

    @elseif($settled->isEmpty())

        {{-- PRE-SETTLEMENT --}}
        <flux:card class="space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading size="lg" level="2">
                        Participation
                    </flux:heading>

                    <flux:text class="mt-1">
                        Offers stay sealed until you settle. You see how many each firm has received, never the amounts.
                        @if (($active = $status->active()) && $active->state === 'open')
                            @if ($active->decision_deadline_at?->isPast())
                                Quarter {{ $active->quarter_number }} was due to close on {{ \App\Support\Dates::dateTime($active->decision_deadline_at) }} and is still open.
                            @else
                                Quarter {{ $active->quarter_number }} closes {{ \App\Support\Dates::dateTime($active->decision_deadline_at) }} ({{ \App\Support\Dates::remaining($active->decision_deadline_at) }}).
                            @endif
                        @endif
                    </flux:text>
                </div>

                <flux:badge>
                    {{ $offerCount }} submitted {{ $offerCount === 1 ? 'offer' : 'offers' }}
                </flux:badge>
            </div>

            @if($participation->isEmpty())
                <flux:callout
                    icon="inbox"
                    heading="No auction targets are available"
                    text="Create firms and assign students before the evaluation stage opens."
                />
            @else
                <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-left dark:border-white/10 dark:bg-white/5">
                            <tr>
                                <th class="p-3 font-medium">Target</th>
                                <th class="p-3 text-right font-medium">
                                    Submitted offers
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach($participation as $target)
                                <tr>
                                    <td class="p-3 font-medium">
                                        {{ $target->name }}
                                    </td>

                                    <td class="p-3 text-right font-mono tabular-nums">
                                        {{ $target->submitted_offer_count }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="border-t border-zinc-200 pt-4 dark:border-white/10">
                @if ($canSettle)
                    <flux:button wire:click="settle" wire:loading.attr="disabled" type="button" icon="lock-closed" variant="primary"
                        wire:confirm="Settle the evaluation stage? The highest offer for each firm wins, and students see the reveal straight away.">
                        Settle and show the reveal
                    </flux:button>
                    <flux:text size="sm" class="mt-2">Settle when you're ready to show the class; you can run the reveal live.</flux:text>
                @else
                    <flux:text size="sm">You can settle once the last quarter has closed.</flux:text>
                @endif
            </div>
        </flux:card>

    @else

        {{-- POST-SETTLEMENT --}}
        <flux:card class="space-y-5">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading size="lg" level="2">
                        Settled outcomes
                    </flux:heading>

                    <flux:badge color="emerald">
                        Auction settled
                    </flux:badge>
                </div>

                <flux:text class="mt-1">
                    Final winners and prices are shown from the frozen auction settlement.
                </flux:text>
            </div>

            <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                <table class="w-full min-w-[820px] text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-left dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="p-3 font-medium">Target</th>
                            <th class="p-3 font-medium">Winner</th>
                            <th class="p-3 text-right font-medium">Price paid</th>
                            <th class="p-3 text-right font-medium">51% true worth</th>
                            <th class="p-3 font-medium">Tie</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                        @foreach($settled as $result)
                            <tr>
                                <td class="p-3 font-medium">
                                    {{ $result->targetFirm->name }}
                                </td>

                                <td class="p-3">
                                    {{ $result->winner?->name ?? 'Unsold' }}
                                </td>

                                <td class="p-3 text-right font-mono tabular-nums">
                                    {{ $result->price_paid === null
                                        ? '—'
                                        : \App\Support\Presentation\FinancialValueFormatter::currency($result->price_paid) }}
                                </td>

                                <td class="p-3 text-right font-mono tabular-nums">
                                    {{ \App\Support\Presentation\FinancialValueFormatter::currency($result->true_stake_worth) }}
                                </td>

                                <td class="p-3">
                                    @if(count($result->tied_top_bidder_ids) > 1)
                                        <flux:badge color="amber">
                                            Exact tie resolved ·
                                            {{ $result->faculty_override
                                                ? 'faculty override'
                                                : 'earliest submission' }}
                                        </flux:badge>
                                    @else
                                        <flux:badge>
                                            No
                                        </flux:badge>
                                    @endif
                                </td>
                            </tr>

                            @if(count($result->tied_top_bidder_ids) > 1)
                                <tr class="bg-zinc-50 dark:bg-white/5">
                                    <td colspan="5" class="p-4">
                                        <div class="grid gap-4 md:grid-cols-[minmax(12rem,auto)_minmax(16rem,1fr)_auto] md:items-end">
                                            <flux:select
                                                wire:model="overrideWinners.{{ $result->id }}"
                                                label="Override tied winner"
                                            >
                                                <flux:select.option value="">
                                                    Select tied bidder
                                                </flux:select.option>

                                                @foreach($result->tied_top_bidder_ids as $firmId)
                                                    <flux:select.option :value="$firmId">
                                                        {{ $course->firms->firstWhere('id', $firmId)?->name }}
                                                    </flux:select.option>
                                                @endforeach
                                            </flux:select>

                                            <flux:input
                                                wire:model="overrideReason"
                                                label="Reason"
                                                placeholder="Reason for faculty override"
                                            />

                                            <flux:button
                                                wire:click="overrideTie('{{ $result->id }}')"
                                                wire:loading.attr="disabled"
                                                icon="arrow-path"
                                                variant="primary"
                                            >
                                                Apply override
                                            </flux:button>
                                        </div>

                                        <flux:text size="sm" class="mt-3">
                                            Faculty override is available only when the highest offers are exactly tied.
                                        </flux:text>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>

        {{-- FUNDING EVIDENCE --}}
        <flux:card class="space-y-5">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading size="lg" level="2">
                        Bidder funding evidence
                    </flux:heading>

                    <flux:text class="mt-1">
                        Funding and valuation evidence associated with the frozen auction snapshot.
                    </flux:text>
                </div>

                @if(!empty($bidderEvidence))
                    <flux:badge>
                        Valuation date:
                        {{ is_array($bidderEvidence)
                            ? ($bidderEvidence[0]['valuation_date'] ?? '—')
                            : ($bidderEvidence->first()['valuation_date'] ?? '—') }}
                    </flux:badge>
                @endif
            </div>

            @if(empty($bidderEvidence))
                <flux:callout
                    icon="inbox"
                    heading="No bidder funding evidence is available"
                />
            @else
                <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-left dark:border-white/10 dark:bg-white/5">
                            <tr>
                                <th class="p-3 font-medium">Target</th>
                                <th class="p-3 font-medium">Bidder</th>
                                <th class="p-3 text-right font-medium">Offer</th>
                                <th class="p-3 text-right font-medium">Fundable cap</th>
                                <th class="p-3 text-right font-medium">51% consultant value</th>
                                <th class="p-3 text-right font-medium">51% true worth</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach($bidderEvidence as $evidence)
                                <tr>
                                    <td class="p-3">
                                        {{ $evidence['target_name'] }}
                                    </td>

                                    <td class="p-3">
                                        {{ $evidence['bidder_name'] }}
                                    </td>

                                    <td class="p-3 text-right font-mono tabular-nums">
                                        {{ \App\Support\Presentation\FinancialValueFormatter::currency($evidence['offered_amount']) }}
                                    </td>

                                    <td class="p-3 text-right font-mono tabular-nums">
                                        {{ \App\Support\Presentation\FinancialValueFormatter::currency($evidence['fundable_cap']) }}
                                    </td>

                                    <td class="p-3 text-right font-mono tabular-nums">
                                        {{ \App\Support\Presentation\FinancialValueFormatter::currency($evidence['consultant_stake_value']) }}
                                    </td>

                                    <td class="p-3 text-right font-mono tabular-nums">
                                        {{ \App\Support\Presentation\FinancialValueFormatter::currency($evidence['true_stake_worth']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </flux:card>

    @endif

    <div
        wire:loading
        role="status"
        class="flex items-center gap-2 text-sm text-zinc-500"
    >
        <flux:icon.loading class="size-4" />
        Processing auction...
    </div>
</div>