@php
    use App\Support\Tour\AdopterTour as T;
    $d = $x['decisions'];
@endphp
<x-tour-frame :crumbs="['Student', 'Spring Section B', 'Alpha', 'Quarter 4 decisions']" user="Maria Chen · Student" menu-title="Alpha" :menu="['Dashboard', 'Decisions', 'Accounting methods', 'History', 'Guide']" active="Decisions">
    <p class="text-lg font-semibold">Quarter 4 decisions</p>
    <p class="text-zinc-500">Every field starts from last quarter, so change only what you want to change.</p>
    <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <p class="mb-2 font-semibold">Forecasting: the orders you expect</p>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($d['forecast'] as $f)
                <div>
                    <p class="text-xs font-medium">{{ $f['label'] }} (units)</p>
                    <p class="mt-1 rounded-md border border-zinc-300 bg-white px-2 py-1.5 tabular-nums dark:border-white/15 dark:bg-zinc-800">{{ T::units($f['value']) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Last quarter's orders: {{ T::units($f['last_orders']) }}</p>
                </div>
            @endforeach
        </div>
    </div>
    <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <p class="mb-2 font-semibold">Sourcing</p>
        <p class="text-xs font-medium">Main supplier</p>
        <p class="mt-1 rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-white/15 dark:bg-zinc-800">{{ $d['supplier'] }}</p>
        <p class="mt-1 text-xs text-zinc-500">Last quarter: {{ $d['previous_supplier'] }}</p>
    </div>
    <div class="flex gap-2"><span class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium dark:border-white/20">Save draft</span><span class="rounded-md bg-emerald-700 px-3 py-1 text-xs font-semibold text-white">Submit for the team</span></div>
</x-tour-frame>
@php
    $raised = collect($d['forecast'])->contains(fn ($f) => $f['value'] > $f['last_orders']);
    $switched = $d['supplier'] !== $d['previous_supplier'];
    $did = array_filter([$raised ? 'raised its forecasts' : null, $switched ? 'changed its main supplier' : null]);
@endphp
@if ($did !== [])
    {{-- Live review R2: say what the team changed, so the text and the picture agree. --}}
    <figcaption class="mt-2 text-xs text-zinc-500 dark:text-zinc-400" data-test="tour-step3-caption">Alpha's Quarter 4 form after the team {{ implode(' and ', $did) }}. The line under each field shows last quarter's figure.</figcaption>
@endif
