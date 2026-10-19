@php
    use App\Support\Tour\AdopterTour as T;
    $c = $x['careful'];
@endphp
<x-tour-frame :crumbs="['Student', 'Spring Section B', 'Alpha', 'History']" user="Maria Chen · Student" menu-title="Alpha" :menu="['Dashboard', 'Decisions', 'Accounting methods', 'History', 'Guide']" active="History">
    <p class="text-lg font-semibold">History</p>
    <p class="text-zinc-500">Quarters 1 to 3 were run for your firm before your team took over.</p>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[30rem] tabular-nums">
            <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="py-1.5 text-left font-medium">Income statement</th>@foreach ([1, 2, 3] as $q)<th class="py-1.5 text-right">Q{{ $q }} · warm-up</th>@endforeach</tr></thead>
            <tbody>
                @foreach (['revenue' => 'Net revenue', 'gross_profit' => 'Gross profit', 'net_income' => 'Net income'] as $key => $label)
                    <tr class="border-b border-zinc-100 dark:border-white/5 {{ $key === 'net_income' ? 'font-semibold' : '' }}"><td class="py-1.5">{{ $label }}</td>@foreach ([1, 2, 3] as $q)<td class="py-1.5 text-right">{{ T::usd($c[$q][$key]) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[30rem] tabular-nums">
            <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="py-1.5 text-left font-medium">Balance sheet</th>@foreach ([1, 2, 3] as $q)<th class="py-1.5 text-right">Q{{ $q }} · warm-up</th>@endforeach</tr></thead>
            <tbody>
                @foreach (['cash' => 'Cash', 'inventory' => 'Inventory'] as $key => $label)
                    <tr class="border-b border-zinc-100 dark:border-white/5 {{ $key === 'cash' ? 'font-semibold' : '' }}"><td class="py-1.5">{{ $label }}</td>@foreach ([1, 2, 3] as $q)<td class="py-1.5 text-right">{{ T::usd($c[$q][$key]) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tour-frame>
