{{--
    Ask Claude on faculty pages (U1): a button on the page, opening a panel down
    the right side. Shown only to faculty who teach the course, on the course's
    pages, once the site has its key and the switch is on.
--}}
@php
    $askClaude = \App\Support\Ai\AskClaudePages::for(request());
@endphp

@if ($askClaude)
    <div class="fixed bottom-[max(1.25rem,env(safe-area-inset-bottom))] end-[max(1.25rem,env(safe-area-inset-right))] z-30">
        <flux:modal.trigger name="ask-claude">
            <flux:button variant="primary" icon="sparkles" data-test="ask-claude-button">Ask Claude</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="ask-claude" variant="flyout" position="right" class="w-full max-w-lg">
        <livewire:ask-claude-panel :course="$askClaude['course']" :context="$askClaude['context']" :suggestions="$askClaude['suggestions']"
            :key="'ask-claude-'.$askClaude['course']->id.'-'.$askClaude['context']['page']" />
    </flux:modal>
@endif
