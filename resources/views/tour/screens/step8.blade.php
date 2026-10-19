@php
    use App\Support\Tour\AdopterTour as T;
    $names = ['careful' => 'Careful', 'average' => 'Average', 'careless' => 'Careless'];
    $dot = ['careful' => 'bg-emerald-700', 'average' => 'bg-sky-800', 'careless' => 'bg-amber-700'];
@endphp
<x-tour-frame :crumbs="['Faculty', 'Spring Section B', 'Practice run']" user="Prof. Rivera · Faculty" menu-title="Spring Section B" :menu="['Review setup', 'Firms', 'Students', 'Quarters', 'Quarter results', 'Practice run', 'Accounting methods', 'Evaluation stage']" active="Practice run">
    <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-lg font-semibold">Evaluation stage</p><span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-white/10">Settled after Quarter {{ $x['quarters'] }}</span></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[24rem] tabular-nums">
            <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="py-1.5 text-left font-medium">Firm</th><th class="py-1.5 text-right">Consultant value</th><th class="py-1.5 text-right">True worth</th></tr></thead>
            <tbody>
                @foreach ($x['evaluation']['firms'] as $f)
                    <tr class="border-b border-zinc-100 dark:border-white/5"><td class="py-1.5"><span class="me-1 inline-block size-2 rounded-full {{ $dot[$f['team']] }}"></span>{{ $names[$f['team']] }}</td><td class="py-1.5 text-right">{{ T::m($f['consultant']) }}</td><td class="py-1.5 text-right">{{ T::m($f['true_worth']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <p class="mb-1 font-semibold">The reveal</p>
        @foreach ($x['evaluation']['results'] as $r)
            @if ($r['winner'])
                <p><span class="font-medium">{{ $names[$r['winner']] }}</span> won {{ $names[$r['target']] }} for {{ T::m($r['price']) }}, {{ T::m(abs($r['difference'])) }} {{ $r['difference'] >= 0 ? 'more' : 'less' }} than the stake was truly worth.</p>
            @endif
        @endforeach
    </div>
</x-tour-frame>
