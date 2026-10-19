@php
    use App\Support\Tour\AdopterTour as T;
    $c = $x['careful'];
    $q4 = $c[4];
@endphp
<x-tour-frame :crumbs="['Student', 'Spring Section B', 'Alpha']" user="Maria Chen · Student" menu-title="Alpha" :menu="['Dashboard', 'Decisions', 'Accounting methods', 'History', 'Guide']" active="Dashboard">
    <p class="text-lg font-semibold">Quarter 4 results</p>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[26rem] tabular-nums">
            <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="py-1.5 text-left font-medium">Measure</th><th class="py-1.5 text-right">Quarter 3</th><th class="py-1.5 text-right">Quarter 4</th></tr></thead>
            <tbody>
                @foreach (['revenue' => 'Net revenue', 'net_income' => 'Net income', 'cash' => 'Cash at quarter end', 'inventory' => 'Inventory'] as $key => $label)
                    <tr class="border-b border-zinc-100 dark:border-white/5 {{ in_array($key, ['net_income', 'cash'], true) ? 'font-semibold' : '' }}"><td class="py-1.5">{{ $label }}</td><td class="py-1.5 text-right">{{ T::usd($c[3][$key]) }}</td><td class="py-1.5 text-right">{{ T::usd($q4[$key]) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <p class="mb-1 font-semibold">Cash flow, Quarter 4 (selected lines)</p>
        <table class="w-full min-w-[22rem] tabular-nums">
            <tbody>
                <tr><td class="py-1">Net income</td><td class="py-1 text-right">{{ T::usd($q4['net_income']) }}</td></tr>
                <tr><td class="py-1">Add back depreciation</td><td class="py-1 text-right">{{ T::usd($q4['depreciation']) }}</td></tr>
                <tr><td class="py-1">Increase in inventory</td><td class="py-1 text-right">{{ T::usd(-$q4['inventory_change']) }}</td></tr>
                <tr><td class="py-1">Increase in accounts receivable</td><td class="py-1 text-right">{{ T::usd(-$q4['receivables_change']) }}</td></tr>
                <tr><td class="py-1">Increase in accounts payable</td><td class="py-1 text-right">{{ T::usd($q4['payables_change']) }}</td></tr>
                <tr class="font-semibold"><td class="py-1">Dividends paid</td><td class="py-1 text-right">{{ T::usd(-$q4['dividend']) }}</td></tr>
            </tbody>
        </table>
    </div>
    <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <p class="font-semibold">Inventory note</p>
        <p class="text-zinc-600 dark:text-zinc-300">Customers ordered {{ T::units($q4['ordered']) }} units and {{ T::units($q4['sold']) }} were sold. {{ T::units($q4['stock']) }} units are left in stock. Materials in transit from the supplier: {{ T::usd($q4['in_transit']) }}, owned from shipment under FOB shipping point.</p>
    </div>
</x-tour-frame>
