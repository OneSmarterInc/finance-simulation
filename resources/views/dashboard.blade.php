<x-layouts::app :title="__('Dashboard')">
    <div class="flexee-page">
        <x-page-header :eyebrow="\App\Support\Product::NAME" :title="'Welcome, '.auth()->user()->name" description="Continue to the existing course and firm tools available to your account." />

        <div class="grid gap-4 md:grid-cols-2">
            @if(auth()->user()->faculty)
                <flux:card class="space-y-4">
                    <div class="flex size-10 items-center justify-center rounded-md bg-emerald-100 text-emerald-800 dark:bg-emerald-400/20 dark:text-emerald-200"><flux:icon.academic-cap /></div>
                    <div><flux:heading size="lg">Faculty workspace</flux:heading><flux:text>Create a course or return through a saved course link to manage firms, quarters and the evaluation stage.</flux:text></div>
                    <flux:button :href="route('faculty.courses.create')" icon="plus" variant="primary">Create course</flux:button>
                </flux:card>
            @endif

            <flux:card class="space-y-4">
                <div class="flex size-10 items-center justify-center rounded-md bg-sky-100 text-sky-800 dark:bg-sky-400/20 dark:text-sky-200"><flux:icon.cog-6-tooth /></div>
                <div><flux:heading size="lg">Account settings</flux:heading><flux:text>Manage your profile, security controls and appearance.</flux:text></div>
                <flux:button :href="route('profile.edit')" icon="cog-6-tooth">Open settings</flux:button>
            </flux:card>

            @if(auth()->user()->student)
                <flux:card class="space-y-3 md:col-span-2">
                    <div class="flex items-center gap-3"><div class="flex size-10 items-center justify-center rounded-md bg-amber-100 text-amber-800 dark:bg-amber-400/20 dark:text-amber-200"><flux:icon.building-office-2 /></div><flux:heading size="lg">Student workspace</flux:heading></div>
                    <flux:text>Use the firm link provided by your faculty course to open decisions, inherited history, the financial dashboard or the evaluation stage.</flux:text>
                </flux:card>
            @endif
        </div>
    </div>
</x-layouts::app>
