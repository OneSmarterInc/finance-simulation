<div class="flexee-page">

    <x-page-header
        eyebrow="FLEXEE ADMINISTRATION"
        :title="'Welcome, ' . auth()->user()->name"
        description="Manage institutions, faculty, students, and simulation courses."
    />

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">

        <flux:card class="!p-5">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                Institutions
            </flux:text>
            <flux:heading size="xl" class="mt-2">
                {{ $institutionCount }}
            </flux:heading>
        </flux:card>

        <flux:card class="!p-5">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                Faculty
            </flux:text>
            <flux:heading size="xl" class="mt-2">
                {{ $facultyCount }}
            </flux:heading>
        </flux:card>

        <flux:card class="!p-5">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                Students
            </flux:text>
            <flux:heading size="xl" class="mt-2">
                {{ $studentCount }}
            </flux:heading>
        </flux:card>

        <flux:card class="!p-5">
            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                Simulation Courses
            </flux:text>
            <flux:heading size="xl" class="mt-2">
                {{ $courseCount }}
            </flux:heading>
        </flux:card>

    </div>

    <flux:card class="mt-6 space-y-4">

        <flux:heading size="lg">
            Simulation Administration
        </flux:heading>

        <flux:text>
            Configure and manage the FLEXEE simulation environment before
            handing the course workflow to Faculty.
        </flux:text>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="building-office">
                Institutions
            </flux:button>

            <flux:button icon="academic-cap">
                Faculty
            </flux:button>

            <flux:button icon="users">
                Students
            </flux:button>

            <flux:button icon="presentation-chart-bar">
                Simulation Courses
            </flux:button>
        </div>

    </flux:card>

</div>
