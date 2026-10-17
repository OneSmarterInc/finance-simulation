<div class="flexee-page">
    <x-page-header eyebrow="Administration" title="Faculty" description="Add faculty to an institution. Courses are then prepared for them under Courses." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    <flux:card>
        <form wire:submit="create" class="grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto] md:items-end">
            <flux:input wire:model="name" label="Name" />
            <flux:input wire:model="email" type="email" label="Email" />
            <flux:select wire:model="institutionId" label="Institution" placeholder="Choose an institution">
                @foreach ($institutions as $institution)
                    <flux:select.option :value="$institution->id">{{ $institution->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" icon="plus" variant="primary">Add faculty</flux:button>
        </form>
    </flux:card>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Email</th>
                        <th class="px-3 py-2 font-medium">Institution</th>
                        <th class="px-3 py-2 font-medium">Courses</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($facultyMembers as $member)
                        <tr wire:key="faculty-{{ $member->id }}">
                            <td class="px-3 py-3 font-medium">{{ $member->user?->name }}</td>
                            <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ $member->user?->email }}</td>
                            <td class="px-3 py-3">
                                @if ($member->courses_count === 0)
                                    <form wire:submit="move('{{ $member->id }}')" class="flex items-center gap-2">
                                        <flux:select size="sm" wire:model="moveTo.{{ $member->id }}">
                                            <flux:select.option value="">{{ $member->institution?->name ?? 'Choose…' }}</flux:select.option>
                                            @foreach ($institutions as $institution)
                                                <flux:select.option :value="$institution->id">{{ $institution->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button size="sm" type="submit">Move</flux:button>
                                    </form>
                                @else
                                    {{ $member->institution?->name }}
                                @endif
                            </td>
                            <td class="px-3 py-3">{{ $member->courses_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-zinc-500">No faculty yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
