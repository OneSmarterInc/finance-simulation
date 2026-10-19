<x-layouts::auth title="Connect Flexee reporting">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Connect Flexee reporting" description="{{ $client->label() }} is asking to read this site's reporting views." />

        <div class="rounded-lg border border-zinc-200 p-4 text-sm leading-6 dark:border-white/10">
            <p class="font-medium">It will be able to read:</p>
            <ul class="mt-2 list-disc space-y-1 ps-5">
                <li>courses, quarters, closes and accounting-method requests, as counts, states, dates and timings;</li>
                <li>AI use and cost, and the site's health.</li>
            </ul>
            <p class="mt-3">It can't change anything, and it never sees a person's name or email, a team's written work or a firm's results. Practice runs are left out.</p>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt class="text-zinc-500">Signed in as</dt>
            <dd class="min-w-0 break-words">{{ auth()->user()->name }} (admin)</dd>
            <dt class="text-zinc-500">Returns to</dt>
            <dd class="min-w-0 break-words font-medium" data-test="return-host">{{ $returnHost }}</dd>
            <dt class="text-zinc-500">Connector</dt>
            <dd class="min-w-0 break-all">{{ $resource }}</dd>
        </dl>

        <form method="POST" action="{{ route('reporting.oauth.decide') }}" class="flex flex-col gap-3">
            @csrf
            @foreach ($params as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach
            <flux:button variant="primary" type="submit" name="decision" value="approve" class="w-full" data-test="approve">Allow read-only access</flux:button>
            <flux:button variant="ghost" type="submit" name="decision" value="deny" class="w-full" data-test="deny">Cancel</flux:button>
        </form>

        <p class="text-center text-xs text-zinc-500">You can end this connection at any time from Admin › Reporting.</p>
    </div>
</x-layouts::auth>
