@php
    use App\Support\Dates;
@endphp
<div class="flexee-page">
    <x-page-header eyebrow="Administration" title="Reporting" description="The read-only connector the family dashboard in Claude reads. It shares operational facts only: no names, no student work and no firm results." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif
    @unless ($enabled)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="The connector is switched off on this site (FLEXEE_REPORTING_ENABLED=false)." />
    @endunless

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="lg">Connect it in Claude</flux:heading>
            <flux:text class="mt-1">Only One Smarter admins can connect. Each person signs in with their own Flexee admin account.</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 break-all rounded-md bg-zinc-100 px-3 py-2 text-sm dark:bg-white/10" data-test="endpoint">{{ $endpoint }}</code>
            <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($endpoint))">Copy</flux:button>
        </div>
        <ol class="list-decimal space-y-1 ps-5 text-sm leading-6">
            <li>An Owner of the One Smarter organization in Claude adds it once: Organization settings › Connectors › Add › Custom, type Web. Paste the address above, and choose "Use Claude's published identity".</li>
            <li>Each admin then connects under Customize › Connectors, finds "Custom", selects Connect, and signs in here.</li>
            <li>On a Pro or Max plan, each person adds it under Customize › Connectors › Add custom connector instead.</li>
        </ol>
    </flux:card>

    <flux:card class="space-y-3">
        <div>
            <flux:heading size="lg">What it shares</flux:heading>
            <flux:text class="mt-1">Family reporting contract {{ config('flexee.reporting.contract') }}. Each view is one tool on the connector. Show rows to see exactly what Claude would get now.</flux:text>
        </div>
        <div class="divide-y divide-zinc-200 dark:divide-white/10">
            @foreach ($tools as $tool => $meta)
                <div class="py-3" wire:key="tool-{{ $tool }}">
                    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                        <p class="min-w-0 font-medium">{{ $meta['title'] }} <code class="ms-1 text-xs text-zinc-500">{{ $tool }}</code></p>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-sm tabular-nums text-zinc-500">{{ number_format($counts[$tool]) }} {{ $counts[$tool] === 1 ? 'row' : 'rows' }}</span>
                            <flux:button size="xs" variant="ghost" wire:click="show('{{ $tool }}')" data-test="show-{{ $tool }}">{{ $showing === $tool ? 'Hide rows' : 'Show rows' }}</flux:button>
                        </div>
                    </div>
                    <p class="mt-1 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">{{ $meta['description'] }}</p>
                    @if ($showing === $tool)
                        <div class="mt-3 overflow-x-auto rounded-lg border border-zinc-200 dark:border-white/10">
                            @if ($rows === [])
                                <p class="px-3 py-4 text-sm text-zinc-500">No rows yet.</p>
                            @elseif (count($rows) === 1)
                                <dl class="grid grid-cols-1 gap-x-6 text-xs sm:grid-cols-2">
                                    @foreach ($rows[0] as $column => $value)
                                        <div class="flex justify-between gap-3 border-b border-zinc-100 px-3 py-1.5 dark:border-white/5">
                                            <dt class="text-zinc-500">{{ $column }}</dt>
                                            <dd class="min-w-0 truncate text-right tabular-nums" title="{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}">{{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @else
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                                            @foreach (array_keys($rows[0]) as $column)
                                                <th class="whitespace-nowrap px-2 py-1.5 font-medium">{{ $column }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                        @foreach (array_slice($rows, 0, 25) as $row)
                                            <tr>
                                                @foreach ($row as $value)
                                                    <td class="whitespace-nowrap px-2 py-1.5 tabular-nums">{{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if (count($rows) > 25)
                                    <p class="border-t border-zinc-200 px-3 py-2 text-xs text-zinc-500 dark:border-white/10">The first 25 of {{ number_format(count($rows)) }} rows.</p>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:card class="space-y-3">
        <flux:heading size="lg">Connections</flux:heading>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                        <th class="px-3 py-2 font-medium">Admin</th>
                        <th class="px-3 py-2 font-medium">Client</th>
                        <th class="px-3 py-2 font-medium">Connected</th>
                        <th class="px-3 py-2 font-medium">Last used</th>
                        <th class="px-3 py-2 font-medium">Calls, 7 days</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($connections as $connection)
                        <tr wire:key="connection-{{ $connection->id }}">
                            <td class="px-3 py-3 font-medium">{{ $connection->user?->name }}</td>
                            <td class="px-3 py-3">{{ $connection->client?->label() }}</td>
                            <td class="px-3 py-3">{{ Dates::dateTime($connection->created_at) }}</td>
                            <td class="px-3 py-3">{{ $connection->last_used_at ? Dates::dateTime($connection->last_used_at) : 'Not yet' }}</td>
                            <td class="px-3 py-3 tabular-nums">{{ number_format($calls[$connection->id] ?? 0) }}</td>
                            <td class="px-3 py-3">
                                @if ($connection->revoked_at)
                                    <span class="text-zinc-500">Revoked {{ Dates::day($connection->revoked_at) }}{{ $connection->revoker ? ' by '.$connection->revoker->name : '' }}</span>
                                @elseif ($connection->isActive())
                                    <span class="text-emerald-700 dark:text-emerald-300">Active</span>
                                @else
                                    <span class="text-zinc-500">Expired</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right">
                                @if ($connection->isActive())
                                    @if ($confirming === $connection->id)
                                        <div class="flex items-center justify-end gap-2">
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400">Revoke this connection?</span>
                                            <flux:button size="xs" variant="danger" wire:click="revoke" data-test="revoke-confirm">Revoke</flux:button>
                                            <flux:button size="xs" variant="ghost" wire:click="$set('confirming', null)">Keep</flux:button>
                                        </div>
                                    @else
                                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="confirmRevoke('{{ $connection->id }}')" data-test="revoke-{{ $connection->id }}">Revoke</flux:button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-zinc-500">No one has connected yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
