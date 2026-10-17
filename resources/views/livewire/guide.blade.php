<div class="flexee-page">
    <x-page-header :eyebrow="\App\Support\Product::NAME" title="Guide"
        :description="$audience === 'faculty' ? 'How to run a course, and the one-page summaries to share with colleagues.' : 'What the course asks of your team, and how to read your results.'" />

    <div class="space-y-6">
        @foreach ($sections as $heading => $html)
            <flux:card class="space-y-3">
                <article class="flexee-prose max-w-3xl text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                    {!! $html !!}
                </article>
            </flux:card>
        @endforeach
    </div>
</div>
