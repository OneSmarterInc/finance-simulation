<div class="flexee-page">
    <x-page-header
        :eyebrow="$firm->course->name"
        :title="$firm->name.' inherited history'"
        description="Validated, read-only warm-up statements for Quarters 1 through 3."
    />

    @forelse($results as $result)
        <flux:card class="space-y-5">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="xl" level="2">Quarter {{ $result->quarter_number }}</flux:heading>
                <flux:badge color="sky" icon="lock-closed">Inherited · read-only</flux:badge>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                @foreach(['income_statement' => 'Income statement', 'balance_sheet' => 'Balance sheet', 'cash_flow_statement' => 'Cash flow statement'] as $key => $label)
                    <section class="min-w-0 rounded-md bg-zinc-50 p-4 dark:bg-white/5">
                        <flux:heading size="lg" level="3">{{ $label }}</flux:heading>
                        <div class="mt-3 overflow-x-auto">
                            <x-financial-array :values="$result->{$key}" currency />
                        </div>
                    </section>
                @endforeach
            </div>
        </flux:card>
    @empty
        <flux:callout icon="clock" heading="Inherited history is not initialized yet" text="Warm-up statements will appear here when initialization is complete." />
    @endforelse
</div>
