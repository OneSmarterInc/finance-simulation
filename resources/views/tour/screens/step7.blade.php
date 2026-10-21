@php
    use App\Support\Tour\AdopterTour as T;
    $value = $x['value_created'];
    // Live review R4: every other quarter from 4, and the last, so the last quarter shows without scrolling.
    $all = array_keys($value['careful']);
    $last = end($all);
    $quarters = array_values(array_filter($all, fn ($q) => ((int) $q >= 4 && ((int) $q - 4) % 2 === 0) || $q === $last));
    $dot = ['careful' => 'bg-emerald-700', 'average' => 'bg-sky-800', 'careless' => 'bg-amber-700'];
@endphp
<x-tour-frame :crumbs="['Faculty', 'Spring Section B', 'Practice run']" user="Prof. Rivera · Faculty" menu-title="Spring Section B" :menu="['Review setup', 'Firms', 'Students', 'Quarters', 'Quarter results', 'Practice run', 'Accounting methods', 'Evaluation stage']" active="Practice run">
    <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-lg font-semibold">Practice run</p><span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-white/10">Played to Quarter {{ $x['quarters'] }} of {{ $x['quarters'] }}</span></div>
    <p class="text-zinc-500">This practice run is private to you. Its firms, students and results never appear in your class.</p>
    <div class="overflow-x-auto">
        <p class="mb-1 font-semibold">Value created</p>
        <table class="w-full min-w-[26rem] tabular-nums">
            <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="sticky left-0 bg-white py-1.5 pe-2 text-left font-medium dark:bg-zinc-900">Team</th>@foreach ($quarters as $q)<th class="py-1.5 ps-3 text-right">Q{{ $q }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach (['careful' => 'Careful', 'average' => 'Average', 'careless' => 'Careless'] as $team => $name)
                    <tr class="border-b border-zinc-100 dark:border-white/5 {{ $team === 'careful' ? 'font-semibold' : '' }}"><td class="sticky left-0 bg-white py-1.5 pe-2 whitespace-nowrap dark:bg-zinc-900"><span class="me-1 inline-block size-2 rounded-full {{ $dot[$team] }}"></span>{{ $name }}</td>@foreach ($quarters as $q)<td class="py-1.5 ps-3 text-right">{{ T::m($value[$team][$q]) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="font-semibold text-emerald-700 dark:text-emerald-400">Careful leads in every quarter from 4 to {{ $x['quarters'] }}, as the reference teams should.</p>
    <div class="flex flex-wrap gap-2"><span class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium dark:border-white/20">View as Careful team</span><span class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium dark:border-white/20">Change the teams</span></div>
</x-tour-frame>
