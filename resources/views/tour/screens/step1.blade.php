<x-tour-frame :crumbs="['Student', 'Spring Section B', 'Alpha']" user="Maria Chen · Student" menu-title="Alpha" :menu="['Dashboard', 'Decisions', 'Accounting methods', 'History', 'Guide']" active="Dashboard">
    <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-400">Spring Section B · Flexee Financial Accounting</p>
    <p class="text-lg font-semibold">Alpha</p>
    <p class="text-zinc-500">Team: Maria Chen, Daniel Ortiz and Priya Nair</p>
    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-400/40 dark:bg-amber-400/10">
        <p class="font-semibold text-amber-900 dark:text-amber-200">Quarter 4 is open. Decisions are due {{ $dates['q4_due'] }}.</p>
        <p class="mt-1 text-amber-900 dark:text-amber-200">Your team hasn't submitted yet. If nothing is submitted by the deadline, last quarter's decisions are used again.</p>
        <span class="mt-2 inline-block rounded-md bg-emerald-700 px-3 py-1 text-xs font-semibold text-white">Go to decisions</span>
    </div>
    <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <p class="font-semibold">Where your firm stands</p>
        <p class="text-zinc-500">Three quarters of history are waiting: statements and notes for Quarters 1 to 3.</p>
    </div>
</x-tour-frame>
