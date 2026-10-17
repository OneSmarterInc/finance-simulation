<div class="flexee-page">
    @php
        $state = strtoupper(
            $record?->state
                ?? ($quarter->quarter_number < 4 ? 'inherited' : 'unavailable')
        );
    @endphp

    <x-page-header
        :eyebrow="$firm->course->name"
        :title="$firm->name.' · Quarter '.$quarter->quarter_number"
        description="Enter and review the operational decisions available for this quarter."
    >
        <x-slot:actions>
            <flux:badge
                :color="$readOnly ? 'zinc' : 'emerald'"
                :icon="$readOnly ? 'lock-closed' : 'pencil-square'"
            >
                {{ $state }}
            </flux:badge>
        </x-slot:actions>
    </x-page-header>

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

    @if($quarter->quarter_number < 4)
        <flux:callout
            icon="lock-closed"
            heading="Inherited warm-up history is read-only"
        />
    @elseif($readOnly)
        <flux:callout
            icon="lock-closed"
            heading="This quarter is locked and read-only"
        />
    @endif

    @foreach($categories as $code => $category)
        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">
                {{ $category->display_name }}
            </flux:heading>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach($categoryFields[$code] ?? [] as $field)
                    <div class="space-y-1">

                        @if(in_array($field['type'] ?? '', ['dropdown', 'select'], true))
                            <flux:select
                                wire:model.live="decisions.{{ $code }}.{{ $field['key'] }}"
                                :disabled="$readOnly"
                                :label="$field['label']"
                            >
                                @foreach($field['allowed_values'] ?? [] as $option)
                                    <flux:select.option :value="$option">
                                        @if($code === 'notes_elections')
                                            {{ str($option)->replace('_', ' ')->title() }}
                                        @else
                                            {{ $option }}
                                        @endif
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:input
                                type="number"
                                step="any"
                                wire:model.live.debounce.500ms="decisions.{{ $code }}.{{ $field['key'] }}"
                                :disabled="$readOnly"
                                :label="$field['label']"
                            />
                        @endif

                        @if(
                            $code === 'notes_elections'
                            && $field['key'] === 'shipping_terms'
                        )
                            <flux:text size="sm">
                                Disclosure policy; no direct financial-statement effect.
                            </flux:text>
                        @endif

                        @if(
                            $code === 'notes_elections'
                            && $field['key'] === 'excise_tax_treatment'
                        )
                            <flux:text size="sm">
                                Classification choice between cost of goods sold and operating expense.
                            </flux:text>
                        @endif

                        @if(isset($field['help_text']))
                            <flux:text size="sm">
                                {{ $field['help_text'] }}
                            </flux:text>
                        @endif

                    </div>
                @endforeach
            </div>
        </flux:card>
    @endforeach

    @if($quarter->quarter_number >= 4)
        <flux:card class="space-y-3">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading size="lg" level="2">
                    Cash Dividend
                </flux:heading>

                <flux:badge color="emerald">
                    Maximum dividend currently available:
                    {{ $maximumDividend === null
                        ? 'Complete the open decisions to calculate'
                        : '$'.number_format($maximumDividend, 2) }}
                </flux:badge>
            </div>

            <flux:input
                class="max-w-sm"
                type="number"
                min="0"
                step="0.01"
                wire:model="dividend"
                :disabled="$readOnly"
                label="Dividend"
            />
        </flux:card>
    @endif

    @unless($readOnly)
        <div class="flex flex-wrap items-center gap-3">
            <flux:button
                wire:click="saveDraft"
                wire:loading.attr="disabled"
                icon="document-check"
            >
                Save draft
            </flux:button>

            <flux:button
                wire:click="submit"
                wire:loading.attr="disabled"
                icon="paper-airplane"
                variant="primary"
            >
                Submit final
            </flux:button>

            <div
                wire:loading
                role="status"
                class="flex items-center gap-2 text-sm text-zinc-500"
            >
                <flux:icon.loading class="size-4" />
                Processing...
            </div>
        </div>
    @endunless

    @if($result)
        <flux:callout
            variant="success"
            icon="document-chart-bar"
            heading="Persisted statements available"
            text="Income statement, balance sheet, cash flow, and notes are available in firm history."
        />
    @endif
</div>