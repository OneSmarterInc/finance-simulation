<div class="space-y-6">
    <x-page-header
        eyebrow="Administration"
        title="Simulation Courses"
        description="View simulation courses, assignments, and deployment status."
    >
        <x-slot:actions>
            <flux:button
                :href="route('admin.courses.create')"
                variant="primary"
                icon="plus"
                wire:navigate
            >
                Add New Course
            </flux:button>

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
                        <th class="px-6 py-4 font-medium">Course</th>
                        <th class="px-6 py-4 font-medium">Institution</th>
                        <th class="px-6 py-4 font-medium">Faculty</th>
                        <th class="px-6 py-4 font-medium">Scenario</th>
                        <th class="px-6 py-4 font-medium">Status</th>
                        <th class="px-6 py-4 text-center font-medium">Quarters</th>
                        <th class="px-6 py-4 text-center font-medium">Firms</th>
                        <th class="px-6 py-4 text-right font-medium">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($courses as $course)
                        <tr>
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">
                                {{ $course->name }}
                            </td>

                            <td class="px-6 py-4 text-zinc-600 dark:text-zinc-300">
                                {{ $course->institution->name }}
                            </td>

                            <td class="px-6 py-4 text-zinc-600 dark:text-zinc-300">
                                {{ $course->faculty->user->name }}
                            </td>

                            <td class="px-6 py-4 text-zinc-600 dark:text-zinc-300">
                                {{ $course->scenario->name }}
                            </td>

                            <td class="px-6 py-4">
                                <flux:badge
                                    :color="$course->status === \App\Models\Course::STATUS_DEPLOYED ? 'green' : 'zinc'"
                                >
                                    {{ ucfirst($course->status) }}
                                </flux:badge>
                            </td>

                            <td class="px-6 py-4 text-center text-zinc-600 dark:text-zinc-300">
                                {{ $course->quarters_count }}
                            </td>

                            <td class="px-6 py-4 text-center text-zinc-600 dark:text-zinc-300">
                                {{ $course->firms_count }}
                            </td>

                            <td class="px-6 py-4 text-right">
                                <flux:button
                                    :href="route('admin.courses.prepare', $course)"
                                    variant="ghost"
                                    size="sm"
                                    wire:navigate
                                >
                                    Prepare / Configure
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="8"
                                class="px-6 py-12 text-center text-zinc-500 dark:text-zinc-400"
                            >
                                No simulation courses found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>