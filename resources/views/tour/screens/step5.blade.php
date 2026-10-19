<x-tour-frame :crumbs="['Faculty', 'Spring Section B', 'Accounting methods']" user="Prof. Rivera · Faculty" menu-title="Spring Section B" :menu="['Review setup', 'Firms', 'Students', 'Quarters', 'Quarter results', 'Practice run', 'Accounting methods', 'Evaluation stage']" active="Accounting methods">
    <p class="text-lg font-semibold">Accounting method requests</p>
    <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-white/10">
        <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">Careless team</p><span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-400/15 dark:text-amber-200">Waiting</span></div>
        <p class="text-xs text-zinc-500">Filed for the team by Sam Okafor, during Quarter 5. Applies from Quarter 6 if you approve now.</p>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[24rem]">
                <thead><tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600"><th class="py-1.5 text-left font-medium">Method</th><th class="py-1.5 text-left font-medium">Now</th><th class="py-1.5 text-left font-medium">Requested</th></tr></thead>
                <tbody><tr class="bg-amber-50 dark:bg-amber-400/10"><td class="py-1.5">Depreciation method</td><td class="py-1.5">Straight-line</td><td class="py-1.5 font-semibold">Double-declining balance</td></tr></tbody>
            </table>
        </div>
        <p class="text-xs text-zinc-500">Puts more depreciation in the early years and less later, so reported profit is lower early and higher later. Cash is unchanged.</p>
        <div class="rounded-md bg-zinc-50 p-2 dark:bg-white/5"><p class="text-xs font-medium">The team's reason</p><p>"Our new line wears fastest in its first years, so most of its cost belongs early."</p></div>
        <div class="flex gap-2"><span class="rounded-md bg-emerald-700 px-3 py-1 text-xs font-semibold text-white">Approve</span><span class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium dark:border-white/20">Decline</span></div>
    </div>
</x-tour-frame>
