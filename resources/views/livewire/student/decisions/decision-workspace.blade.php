@php
    use App\Support\Dates;
    use App\Support\Presentation\DecisionPresenter as Names;
    use App\Support\Presentation\StatementPresenter;

    $unitsHint = [
        'shifts' => 'Each shift gives 250,000 units of capacity a quarter.',
        'primary_alloc' => 'The rest comes from the second supplier.',
        'marketing_budget_pct' => 'For example, 5 means 5% of revenue.',
        'inspection_level' => 'None costs nothing and catches no defects. Basic costs $2 a unit and catches 70%. Full costs $5 a unit and catches 95%.',
        'logistics_mode' => 'Standard costs $8 a unit and arrives on time 88% of the time. Express: $15 and 95%. Air freight: $35 and 99%.',
        'postponed_flag' => 'Saves 60% of the excise duty on the units made this way.',
        'postponed_pct' => 'Only used when postponed production is on.',
    ];
    $intermediates = $lastResult?->intermediates ?? [];
@endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$firm->course->name" :title="$firm->name.' · Quarter '.$quarter->quarter_number.' decisions'"
        description="These decisions give your firm a new set of statements to read. Every field starts from last quarter, so change only what you want to change.">
        <x-slot:actions>
            @if ($teamStatus)
                <flux:badge :color="in_array($teamStatus['state'], ['submitted', 'locked'], true) ? 'emerald' : 'amber'">{{ $teamStatus['label'] }}</flux:badge>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    @if ($quarter->quarter_number < 4)
        <flux:callout icon="lock-closed" heading="Quarters 1 to 3 were run for your firm before you took over." />
    @elseif ($readOnly)
        <flux:callout icon="lock-closed" heading="This quarter's decisions are locked." text="Your results and the decisions behind them are on the History page." />
    @else
        <flux:callout icon="clock" :heading="'Due '.Dates::dateTime($quarter->decision_deadline_at).' ('.Dates::remaining($quarter->decision_deadline_at).')'">
            <flux:callout.text>
                @if ($prefilledFromLastQuarter)
                    Every field below starts from last quarter's decisions.
                @endif
                Anyone on the team can save a draft or submit. If nothing is submitted by the deadline, last quarter's decisions are used again.
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Last quarter at a glance (item 79) --}}
    @if ($lastResult && $quarter->quarter_number >= 4)
        <flux:card class="space-y-3">
            <flux:heading size="lg" level="2">Last quarter at a glance (Quarter {{ $lastResult->quarter_number }})</flux:heading>
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['p1' => 'Product 1', 'p2' => 'Product 2'] as $p => $label)
                    @if (isset($intermediates[$p.'_sold']))
                        <div class="rounded-md bg-zinc-50 p-3 dark:bg-white/5">
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd>Made {{ number_format((float) ($previous['production'][$p.'_production'] ?? 0)) }}, sold {{ number_format((float) $intermediates[$p.'_sold']) }}, unsold {{ number_format((float) ($intermediates[$p.'_unsold'] ?? 0)) }}</dd>
                        </div>
                    @endif
                @endforeach
                <div class="rounded-md bg-zinc-50 p-3 dark:bg-white/5">
                    <dt class="text-zinc-500">Plant capacity used</dt>
                    <dd>{{ StatementPresenter::format((float) ($lastResult->fields['n4_util_rate'] ?? 0), 'percent') }} of {{ number_format(250000 * (int) ($previous['production']['shifts'] ?? 1)) }} units</dd>
                </div>
                <div class="rounded-md bg-zinc-50 p-3 dark:bg-white/5">
                    <dt class="text-zinc-500">Net income · cash</dt>
                    <dd>{{ StatementPresenter::accounting((float) ($lastResult->income_statement['net_income'] ?? 0)) }} · {{ StatementPresenter::accounting((float) ($lastResult->fields['bs_cash'] ?? 0)) }}</dd>
                </div>
            </dl>
        </flux:card>
    @endif

    @foreach ($categories as $code => $category)
        <flux:card class="space-y-4" wire:key="category-{{ $code }}">
            <flux:heading size="lg" level="2">{{ Names::categoryLabel($code) }}</flux:heading>

            <div class="grid gap-x-6 gap-y-6 md:grid-cols-2">
                @foreach ($categoryFields[$code] ?? [] as $field)
                    @php
                        $key = $field['key'];
                        $isPercent = in_array($key, Names::PERCENT_FIELDS, true);
                        $last = array_key_exists($key, $previous[$code] ?? []) ? Names::valueLabel($key, $previous[$code][$key], $parameters) : null;
                    @endphp
                    <div class="space-y-1" wire:key="field-{{ $code }}-{{ $key }}">
                        @if (in_array($field['type'] ?? '', ['dropdown', 'select'], true))
                            <flux:select wire:model.live="decisions.{{ $code }}.{{ $key }}" :disabled="$readOnly" :label="Names::fieldLabel($key)">
                                @foreach ($field['allowed_values'] ?? [] as $option)
                                    <flux:select.option :value="$option">
                                        {{ in_array($key, ['primary_supplier', 'secondary_supplier'], true) ? Names::supplierDetail($option, $parameters) : Names::valueLabel($key, $option, $parameters) }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:input type="number" step="any" min="0"
                                wire:model.live.debounce.500ms="decisions.{{ $code }}.{{ $key }}"
                                :disabled="$readOnly" :label="Names::fieldLabel($key).($isPercent ? ' (%)' : '')" />
                        @endif

                        @if ($last !== null)
                            <flux:text size="sm">Last quarter: {{ $last }}</flux:text>
                        @endif
                        @if (isset($unitsHint[$key]))
                            <flux:text size="sm">{{ $unitsHint[$key] }}</flux:text>
                        @elseif (in_array($key, Names::PRICE_FIELDS, true))
                            <flux:text size="sm">Base price {{ '$'.number_format((float) ($parameters[str_starts_with($key, 'price_p1') ? 'p1_base_price' : 'p2_base_price'] ?? 0), 2) }}.</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($code === 'marketing')
                @php($segments = collect(['marketing_alloc_champions', 'marketing_alloc_growth', 'marketing_alloc_at_risk', 'marketing_alloc_other'])->sum(fn ($k) => (float) ($decisions['marketing'][$k] ?? 0)))
                <p @class(['text-sm', 'text-emerald-700 dark:text-emerald-300' => abs($segments - 100) < 0.01, 'text-amber-700 dark:text-amber-300' => abs($segments - 100) >= 0.01])>
                    Segment shares total {{ rtrim(rtrim(number_format($segments, 2), '0'), '.') }}%{{ abs($segments - 100) < 0.01 ? '.' : '. They should add up to 100%.' }}
                </p>
            @endif
        </flux:card>
    @endforeach

    @if ($quarter->quarter_number >= 4)
        <flux:card class="space-y-3">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading size="lg" level="2">Dividend</flux:heading>
                <flux:badge color="emerald">
                    Most you can pay now:
                    {{ $maximumDividend === null ? 'fill in the decisions above to see' : '$'.number_format($maximumDividend, 2) }}
                </flux:badge>
            </div>
            <flux:input class="max-w-sm" type="number" min="0" step="0.01" wire:model="dividend" :disabled="$readOnly" label="Dividend to declare ($)" />
        </flux:card>
    @endif

    @unless ($readOnly)
        <div class="flex flex-wrap items-center gap-3">
            <flux:button wire:click="saveDraft" wire:loading.attr="disabled" icon="document-check">Save draft</flux:button>
            <flux:button wire:click="submit" wire:loading.attr="disabled" icon="paper-airplane" variant="primary">Submit for the team</flux:button>
            <div wire:loading role="status" class="flex items-center gap-2 text-sm text-zinc-500">
                <flux:icon.loading class="size-4" /> Saving...
            </div>
        </div>
    @endunless

    @if ($result)
        <flux:callout variant="success" icon="document-chart-bar" heading="This quarter's results are ready"
            text="The statements and the decisions behind them are on your dashboard and the History page." />
    @endif
</div>
