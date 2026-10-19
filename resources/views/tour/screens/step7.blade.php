@php
    use App\Support\Tour\AdopterTour as T;
    $value = $x['value_created'];
    $quarters = array_keys($value['careful']);
    $dot = ['careful' => 'bg-emerald-700', 'average' => 'bg-sky-800', 'careless' => 'bg-amber-700'];
@endphp
<x-tour-frame :crumbs="['Faculty', 'Spring Section B', 'Practice run']" user="Prof. Rivera · Faculty" menu-title="Spring Section B" :menu="['Review setup', 'Firms', 'Students', 'Quarters', 'Quarter results', 'Practice run', 'Accounting methods', 'Evaluation stage']" active="Practice run">
    <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-lg font-semibold">Practice run</p><span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-white/10">Played to Quarter {{ $x['quarters'] }} of {{ $x['quarters'] }}</span></div>
    <p class="text-zinc-500">This practice run is private to you. Its firms, students and results never appear in your class.</p>
    <div class="overflow-x-auto">
        <p class="mb-1 font-semibold">Value created</p>
        <table class="w-full min-w-[34rem] tabular-nums">
            <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="py-1.5 text-left font-medium">Team</th>@foreach ($quarters as $q)<th class="py-1.5 text-right">Q{{ $q }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach (['careful' => 'Careful', 'average' => 'Average', 'careless' => 'Careless'] as $team => $name)
                    <tr class="border-b border-zinc-100 dark:border-white/5 {{ $team === 'careful' ? 'font-semibold' : '' }}"><td class="py-1.5 whitespace-nowrap"><span class="me-1 inline-block size-2 rounded-full {{ $dot[$team] }}"></span>{{ $name }}</td>@foreach ($quarters as $q)<td class="py-1.5 text-right">{{ T::m($value[$team][$q]) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="font-semibold text-emerald-700 dark:text-emerald-400">Careful leads in every quarter from 4 to {{ $x['quarters'] }}, as the reference teams should.</p>
    <div class="flex flex-wrap gap-2"><span class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium dark:border-white/20">View as Careful team</span><span class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium dark:border-white/20">Change the teams</span></div>
</x-tour-frame>
