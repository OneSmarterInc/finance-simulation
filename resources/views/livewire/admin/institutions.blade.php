<div class="flexee-page">

    <x-page-header
        eyebrow="FLEXEE ADMINISTRATION"
        title="Institutions"
        description="View institutions and their simulation activity."
    >
        <x-slot:actions>
            <flux:button :href="route('admin.dashboard')" icon="arrow-left">
                Dashboard
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-4">

        @forelse ($institutions as $institution)

            <flux:card class="!p-5">

                <div class="flex items-start justify-between gap-4">

                    <div>
                        <flux:heading size="lg">
                            {{ $institution->name }}
                        </flux:heading>

                        <flux:text class="mt-1">
                            Institution
                        </flux:text>
                    </div>

                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3">

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                            Faculty
                        </div>
                        <div class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ $institution->faculty_count }}
                        </div>
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                            Students
                        </div>
                        <div class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ $institution->students_count }}
                        </div>
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                            Courses
                        </div>
                        <div class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ $institution->courses_count }}
                        </div>
                    </div>

                </div>

            </flux:card>

        @empty

            <flux:card class="space-y-3">
                <flux:heading size="lg">
                    No institutions
                </flux:heading>

                <flux:text>
                    There are currently no institutions configured.
                </flux:text>
            </flux:card>

        @endforelse

    </div>

</div>
