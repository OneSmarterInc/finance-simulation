<x-layouts::student :title="__('Access pending')">
    <div class="flexee-page">
        <x-page-header :eyebrow="$firm->course->name" :title="$firm->name" />
        <flux:callout icon="clock" heading="Access opens once your registration is confirmed">
            You're registered for this course and placed in {{ $firm->name }}. The firm's pages open as soon as your registration is confirmed, usually within a day of payment. If it's taking longer, let your instructor know.
        </flux:callout>
        <div>
            <flux:button :href="route('student.courses')" icon="arrow-left" wire:navigate>My courses</flux:button>
        </div>
    </div>
</x-layouts::student>
