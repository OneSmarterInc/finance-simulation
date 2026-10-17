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
                                    Accounting elections
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
                                            {{ str($row['reveal']['outcome'])->title() }}
                                        </flux:badge>
                                    </dd>
                                </div>

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