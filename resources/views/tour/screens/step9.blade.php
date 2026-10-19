@php
    use App\Support\Tour\AdopterTour as T;
@endphp
<x-tour-frame :crumbs="['Ask for a trial course']">
    <p class="text-lg font-semibold">Ask for a trial course</p>
    <p class="text-zinc-600 dark:text-zinc-300">Write to {{ T::CONTACT_NAME }} with your institution, your course and your term dates, and we'll set up a course you can rehearse straight away.</p>
    <div class="flex flex-wrap items-center gap-2">
        <code class="rounded-md border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-base select-all dark:border-white/15 dark:bg-zinc-800" data-test="tour-contact">{{ T::CONTACT }}</code>
        <button type="button" class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-white/20 dark:hover:bg-white/10"
            onclick="navigator.clipboard.writeText('{{ T::CONTACT }}').then(() => { this.textContent = 'Copied'; }).catch(() => { const r = document.createRange(); r.selectNodeContents(this.previousElementSibling); const s = getSelection(); s.removeAllRanges(); s.addRange(r); });">Copy</button>
    </div>
    <p class="text-xs text-zinc-500">The address is shown to copy, not a form, so nothing is sent from this page.</p>
    <div class="flex flex-wrap gap-2 pt-1">
        <a href="{{ route('login') }}" class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-white/20 dark:hover:bg-white/10">Sign in</a>
        <a href="{{ route('tour', 1) }}" class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:border-white/20 dark:hover:bg-white/10">Start the tour again</a>
    </div>
</x-tour-frame>
