<x-layouts::auth title="Flexee reporting">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Can't connect" description="The Flexee reporting connector didn't accept this sign-in." />
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" role="alert" />
        <div class="text-center text-sm">
            <flux:link :href="route('home')">Back to Flexee</flux:link>
        </div>
    </div>
</x-layouts::auth>
