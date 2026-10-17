<div class="space-y-6">
    <x-page-header
        eyebrow="FLEXEE ADMINISTRATION"
        title="Students"
        description="View students assigned to institutions and their simulation activity."
    >
        <x-slot:actions>
            <flux:button
                :href="route('admin.dashboard')"
                variant="ghost"
                icon="arrow-left"
                wire:navigate
            >
                Dashboard
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-6 py-4 font-medium">Student</th>
                        <th class="px-6 py-4 font-medium">Email</th>
                        <th class="px-6 py-4 font-medium">Institution</th>
                        <th class="px-6 py-4 text-center font-medium">Enrollments</th>
                        <th class="px-6 py-4 text-center font-medium">Firms</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($students as $student)
                        <tr>
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">
                                {{ $student->user->name }}
                            </td>

                            <td class="px-6 py-4 text-zinc-600 dark:text-zinc-300">
                                {{ $student->user->email }}
                            </td>

                            <td class="px-6 py-4 text-zinc-600 dark:text-zinc-300">
                                {{ $student->institution->name }}
                            </td>

                            <td class="px-6 py-4 text-center text-zinc-600 dark:text-zinc-300">
                                {{ $student->enrollments_count }}
                            </td>

                            <td class="px-6 py-4 text-center text-zinc-600 dark:text-zinc-300">
                                {{ $student->firm_memberships_count }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="5"
                                class="px-6 py-12 text-center text-zinc-500 dark:text-zinc-400"
                            >
                                No students found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>