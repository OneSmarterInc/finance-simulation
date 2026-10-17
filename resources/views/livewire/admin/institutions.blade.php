<div class="flexee-page">
    <x-page-header eyebrow="Administration" title="Institutions" description="Add an institution before adding its faculty and courses." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    <flux:card>
        <form wire:submit="create" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="name" label="New institution" placeholder="Institution name" class="min-w-72" />
            <flux:button type="submit" icon="plus" variant="primary">Add institution</flux:button>
        </form>
    </flux:card>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                        <th class="px-3 py-2 font-medium">Institution</th>
                        <th class="px-3 py-2 font-medium">Faculty</th>
                        <th class="px-3 py-2 font-medium">Students</th>
                        <th class="px-3 py-2 font-medium">Courses</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($institutions as $institution)
                        <tr wire:key="institution-{{ $institution->id }}">
                            <td class="px-3 py-3 font-medium">
                                @if ($editingId === $institution->id)
                                    <form wire:submit="rename" class="flex items-center gap-2">
                                        <flux:input size="sm" wire:model="editingName" />
                                        <flux:button size="sm" type="submit">Save</flux:button>
                                    </form>
                                @else
                                    {{ $institution->name }}
                                @endif
                            </td>
                            <td class="px-3 py-3">{{ $institution->faculty_count }}</td>
                            <td class="px-3 py-3">{{ $institution->students_count }}</td>
                            <td class="px-3 py-3">{{ $institution->courses_count }}</td>
                            <td class="px-3 py-3 text-right">
                                @if ($editingId !== $institution->id)
                                    <flux:button size="xs" variant="ghost" icon="pencil" wire:click="edit('{{ $institution->id }}')">Rename</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-zinc-500">No institutions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
