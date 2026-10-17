<div class="flexee-page">
    <x-page-header
        :eyebrow="$firm->course->name"
        :title="$firm->name.' history'"
        description="Every closed quarter side by side, with the decisions that produced it. Quarters 1 to 3 are the warm-up, run for the firm before the team took over."
    />

    @if ($results->isEmpty())
        <flux:callout icon="clock" heading="No quarters yet" text="The warm-up quarters appear here as soon as the firm is set up." />
    @else
        <flux:card>
            <x-firm-report :columns="$columns" :decisions="$decisionColumns" :disclosures="$disclosureColumns" :parameters="$parameters" />
        </flux:card>
    @endif
</div>
