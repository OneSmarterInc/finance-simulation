<div class="flexee-page">
    <x-page-header
        :eyebrow="$firm->course->name.' · Evaluation stage'"
        title="Rival evaluation and sealed offers"
        description="Evaluate disclosed statements and the consultant report. Every offer is for a fixed 51% controlling interest."
    />

    @if($statusMessage)
        <flux:callout
            variant="success"
            icon="check-circle"
            :heading="$statusMessage"
            role="status"
        />
    @endif

    @if($availabilityMessage !== null)
        <flux:callout
            variant="warning"
            icon="clock"
            heading="The evaluation stage hasn't opened yet"
        >
            <flux:text>
                {{ \App\Services\Courses\CourseStatus::for($firm->course)->evaluationOpensText() }}
            </flux:text>

            <flux:text class="mt-2">
                In the evaluation stage, every firm's published statements and notes become visible to the other teams.
                For each rival your team receives a consultant's valuation, decides what a controlling stake is worth,
                and places a sealed offer it can afford to fund. When your instructor settles the stage, each firm's
                true worth is shown beside the price paid.
            </flux:text>
        </flux:callout>
    @else
        <div class="space-y-6">
            @foreach($rows as $row)
                <flux:card
                    class="space-y-5"
                    wire:key="target-{{ $row['target']->id }}"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0 space-y-1">
                            <flux:heading size="xl" level="2">
                                {{ $row['target']->name }}
                            </flux:heading>

                            <flux:text>
                                Published statements and locked accounting policies only.
                            </flux:text>
                        </div>

                        <dl class="grid shrink-0 gap-3 sm:grid-cols-2">
                            <div class="rounded-md bg-zinc-50 px-4 py-3 dark:bg-white/5">
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Whole-company consultant value
                                </dt>

                                <dd class="mt-1 text-right font-mono text-base font-semibold tabular-nums">
                                    {{ \App\Support\Presentation\FinancialValueFormatter::currency(max(0, (float) $row['report']?->consultant_value)) }}
                                </dd>
                            </div>

                            <div class="rounded-md bg-zinc-50 px-4 py-3 dark:bg-white/5">
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Implied 51% consultant value
                                </dt>

                                <dd class="mt-1 text-right font-mono text-base font-semibold tabular-nums">
                                    {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['consultant_stake_value']) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    @if((float) $row['report']?->consultant_value <= 0)
                        <flux:callout
                            variant="danger"
                            icon="exclamation-triangle"
                            heading="NO POSITIVE GOING-CONCERN VALUE"
                        />
                    @endif

                    <details class="flexee-disclosure">
                        <summary>Consultant report and disclosed policies</summary>

                        <div class="mt-4 grid gap-6 lg:grid-cols-2">
                            <x-financial-array
                                :values="$row['report']?->template_sections ?? []"
                            />

                            <div>
                                <p class="flexee-section-label mb-2">
                                    Accounting methods
                                </p>

                                <x-financial-array
                                    :values="$row['snapshot']->accounting_elections"
                                />
                            </div>
                        </div>
                    </details>

                    <details class="flexee-disclosure">
                        <summary>Published financial statements</summary>

                        <div class="mt-4 grid gap-6 xl:grid-cols-2">
                            @foreach($row['snapshot']->published_statements as $statement)
                                <section class="min-w-0 rounded-md bg-zinc-50 p-4 dark:bg-white/5">
                                    <flux:heading size="lg">
                                        Quarter {{ $statement['quarter_number'] }}
                                    </flux:heading>

                                    <div class="mt-3">
                                        <x-financial-array
                                            :values="$statement"
                                            currency
                                        />
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </details>

                    {{-- U11 layer 3 (RA1, RA2): the team's analysis of this rival, needed before a positive offer. --}}
                    @php($analysisRow = $analyses->get($row['target']->id))
                    @if($analysisRequired && ! $settled)
                        <section
                            x-data="{ open: @js($analysisRow !== null && ! $analysisRow->isSubmitted()) }"
                            class="rounded-md border border-sky-200 bg-sky-50/60 dark:border-sky-400/20 dark:bg-sky-400/5"
                            data-test="analysis-{{ $row['target']->id }}"
                        >
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                                class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-left">
                                <span class="flex items-center gap-2">
                                    <flux:icon.pencil-square variant="micro" class="text-sky-700 dark:text-sky-300" />
                                    <span class="font-semibold">Your team's analysis of {{ $row['target']->name }}</span>
                                </span>
                                @if($analysisRow?->isSubmitted())
                                    <flux:badge color="emerald" size="sm" class="!whitespace-normal">Submitted by {{ $analysisRow->submittedBy?->user?->name ?? 'your team' }}, {{ \App\Support\Dates::dateTime($analysisRow->submitted_at) }}</flux:badge>
                                @elseif($analysisRow)
                                    <flux:badge color="amber" size="sm">Draft saved, not submitted</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Needed before a positive offer</flux:badge>
                                @endif
                            </button>

                            <div x-show="open" x-cloak class="space-y-4 border-t border-sky-200 px-4 pt-3 pb-4 dark:border-sky-400/20">
                                <flux:text size="sm" class="max-w-3xl">
                                    Write this before you save a positive offer for {{ $row['target']->name }}. A $0 offer needs none.
                                    Your instructor reads it after the evaluation stage is settled. Other teams never see it.
                                </flux:text>

                                @foreach([
                                    'matters' => ['What matters most?', 'Name two or three things in '.$row['target']->name."'s statements and notes that matter most for what it's worth."],
                                    'misses' => ["What does the consultant's number miss?", 'The consultant works from reported income. Say what '.$row['target']->name."'s methods or notes show that the number leaves out or overstates."],
                                    'worth' => ['What is 51% worth to you, and why?', "Give your own figure below and say how you got there. It doesn't have to equal your offer."],
                                ] as $key => [$label, $hint])
                                    <flux:field>
                                        <flux:label>{{ $label }}</flux:label>
                                        <flux:description>{{ $hint }}</flux:description>
                                        <flux:textarea wire:model="analysis.{{ $row['target']->id }}.{{ $key }}" rows="3" :disabled="! $analysisEditable" />
                                        <flux:error name="analysis.{{ $row['target']->id }}.{{ $key }}" />
                                    </flux:field>
                                @endforeach

                                <flux:field class="max-w-sm">
                                    <flux:label>Your figure for 51% of {{ $row['target']->name }}</flux:label>
                                    <flux:input wire:model="analysis.{{ $row['target']->id }}.estimate" type="number" min="0" step="0.01" icon="currency-dollar" :disabled="! $analysisEditable" />
                                    <flux:error name="analysis.{{ $row['target']->id }}.estimate" />
                                </flux:field>

                                <flux:error name="analysis.{{ $row['target']->id }}.analysis" />

                                @if($analysisEditable)
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button type="button" wire:click="saveAnalysis('{{ $row['target']->id }}')">Save draft</flux:button>
                                        <flux:button type="button" variant="primary" wire:click="submitAnalysis('{{ $row['target']->id }}')">
                                            {{ $analysisRow?->isSubmitted() ? 'Submit the changes' : 'Submit the analysis' }}
                                        </flux:button>
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endif

                    @if(!$settled)
                        <div class="grid gap-5 rounded-md border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                            <div class="min-w-0 space-y-3">
                                <div>
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                        Maximum fundable offer
                                    </p>

                                    <p class="font-mono text-xl font-semibold tabular-nums">
                                        {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['funding']['maximum_fundable_offer']) }}
                                    </p>

                                    <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                                        Cash {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['funding']['bidder_cash']) }}
                                        +
                                        unused committed credit {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['funding']['unused_committed_credit']) }}
                                        +
                                        target financing {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['funding']['target_acquisition_financing']) }}.
                                        Overdraft excluded.
                                    </p>
                                </div>

                                @if($analysisRequired && ! $analysisRow?->isSubmitted())
                                    <p class="text-sm text-zinc-600 dark:text-zinc-300" data-test="offer-needs-analysis">
                                        A positive offer needs your team's analysis of {{ $row['target']->name }} first. A $0 offer doesn't.
                                    </p>
                                @endif

                                <flux:input
                                    wire:model="offerAmounts.{{ $row['target']->id }}"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    label="Your sealed offer"
                                />

                                @error('offerAmounts.'.$row['target']->id)
                                    <p class="text-sm text-red-700 dark:text-red-300">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <flux:button
                                wire:click="saveOffer('{{ $row['target']->id }}')"
                                wire:loading.attr="disabled"
                                type="button"
                                icon="lock-closed"
                                variant="primary"
                            >
                                Save sealed offer
                            </flux:button>
                        </div>
                    @else
                        <div class="space-y-4 rounded-md border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <div>
                                <flux:heading size="lg">
                                    Auction reveal
                                </flux:heading>

                                <flux:text size="sm">
                                    Valuation date: {{ $row['reveal']['valuation_date'] }}
                                </flux:text>
                            </div>

                            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <dt class="text-sm text-zinc-500">
                                        51% true worth
                                    </dt>

                                    <dd class="font-mono tabular-nums">
                                        {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['reveal']['true_stake_worth']) }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-sm text-zinc-500">
                                        Price paid
                                    </dt>

                                    <dd class="font-mono tabular-nums">
                                        {{ $row['reveal']['price_paid'] === null
                                            ? 'Unsold'
                                            : \App\Support\Presentation\FinancialValueFormatter::currency($row['reveal']['price_paid']) }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-sm text-zinc-500">
                                        Outcome
                                    </dt>

                                    <dd>
                                        <flux:badge>
                                            {{ ['unsold' => 'Not sold', 'overpaid' => 'Paid more than true worth', 'underpaid' => 'Paid less than true worth', 'exact' => 'Paid exactly its true worth'][$row['reveal']['outcome']] ?? $row['reveal']['outcome'] }}
                                        </flux:badge>
                                    </dd>
                                </div>

                                @if($analysisRow?->estimated_stake_value !== null)
                                    <div>
                                        <dt class="text-sm text-zinc-500">
                                            Your team's figure for 51%
                                        </dt>

                                        <dd class="font-mono tabular-nums">
                                            {{ \App\Support\Presentation\FinancialValueFormatter::currency($analysisRow->estimated_stake_value) }}
                                        </dd>
                                    </div>
                                @endif

                                @if($row['reveal']['viewer_offer'] !== null)
                                    <div>
                                        <dt class="text-sm text-zinc-500">
                                            Your offer
                                        </dt>

                                        <dd class="font-mono tabular-nums">
                                            {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['reveal']['viewer_offer']) }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-sm text-zinc-500">
                                            Your fundable maximum
                                        </dt>

                                        <dd class="font-mono tabular-nums">
                                            {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['reveal']['viewer_fundable_cap']) }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-sm text-zinc-500">
                                            Unused capacity
                                        </dt>

                                        <dd class="font-mono tabular-nums">
                                            {{ \App\Support\Presentation\FinancialValueFormatter::currency($row['reveal']['viewer_unused_capacity']) }}
                                        </dd>
                                    </div>
                                @endif
                            </dl>

                            @if($analysisRow)
                                <details class="flexee-disclosure" data-test="analysis-after-{{ $row['target']->id }}">
                                    <summary>Your team's analysis of {{ $row['target']->name }}{{ $analysisRow->faculty_comment ? ', with your instructor\'s comment' : '' }}</summary>
                                    <dl class="mt-3 grid gap-3 text-sm md:grid-cols-3">
                                        @foreach(\App\Models\FirmRivalAnalysis::PROMPTS as $key => $label)
                                            <div class="min-w-0">
                                                <dt class="font-medium">{{ $label }}</dt>
                                                <dd class="mt-1 whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ ($analysisRow->answers[$key] ?? '') !== '' ? $analysisRow->answers[$key] : 'Not written.' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    @if($analysisRow->faculty_comment)
                                        <div class="mt-3 rounded-lg bg-white p-3 text-sm dark:bg-white/5">
                                            <p class="font-medium">Instructor's comment</p>
                                            <p class="mt-1 whitespace-pre-line">{{ $analysisRow->faculty_comment }}</p>
                                        </div>
                                    @endif
                                </details>
                            @endif
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>

        <div
            wire:loading
            role="status"
            class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400"
        >
            <flux:icon.loading class="size-4" />
            <span>Saving sealed offer...</span>
        </div>
    @endif
</div>