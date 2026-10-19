<div class="flexee-page">
    <x-page-header :eyebrow="\App\Support\Product::NAME" title="Guide"
        :description="$audience === 'faculty' ? 'How to run a course, and the one-page summaries to share with colleagues.' : 'What the course asks of your team, and how to read your results.'" />

    <div class="space-y-6">
        {{-- U4 layer 2: the two explainers, for students and faculty alike. --}}
        <section class="space-y-3" aria-labelledby="see-it-move">
            <div>
                <flux:heading size="lg" level="2" id="see-it-move">See it move</flux:heading>
                <flux:text class="mt-1">{{ $audience === 'faculty' ? 'Two one-minute explainers your students can play from their own Guide: how a quarter works, and how one accounting choice moves all four statements.' : 'Two one-minute explainers: how a quarter works, and how one accounting choice moves all four statements.' }}</flux:text>
            </div>
            <div class="max-w-4xl space-y-4">
                @foreach ($explainers as $explainer)
                    <x-explainer :explainer="$explainer" class="min-w-0" />
                @endforeach
            </div>
        </section>

        @foreach ($sections as $heading => $html)
            <flux:card class="space-y-3">
                <article class="flexee-prose max-w-3xl text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                    {!! $html !!}
                </article>
            </flux:card>
        @endforeach
    </div>
</div>
