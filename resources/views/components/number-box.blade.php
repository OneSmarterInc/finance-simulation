{{--
    Live walkthrough L13: a number box that shows thousands separators
    ("30,199"), so a student can read their own entry at a glance. The box
    is plain text with a numeric keyboard; what reaches the server is the
    number without separators, sent with the next request like wire:model.
    Anything that isn't a number is passed on as typed, for the form's own
    checks to explain.
--}}
@props(['model', 'label' => null, 'disabled' => false])

<div x-data="{
        value: $wire.entangle(@js($model)),
        shown: '',
        fmt(v) {
            if (v === null || v === undefined || v === '') return '';
            const n = Number(String(v).replace(/,/g, ''));
            return Number.isFinite(n) ? n.toLocaleString('en-US', { maximumFractionDigits: 6 }) : String(v);
        },
        sync() {
            const t = String(this.shown).replace(/,/g, '').trim();
            this.value = t !== '' && Number.isFinite(Number(t)) ? Number(t) : t;
        },
        init() {
            this.shown = this.fmt(this.value);
            this.$watch('value', (v) => {
                if (! this.$el.contains(document.activeElement)) this.shown = this.fmt(v);
            });
        },
    }">
    <flux:input type="text" inputmode="decimal" autocomplete="off" x-model="shown" x-on:input="sync()" x-on:blur="shown = fmt(value)"
        :disabled="$disabled" :label="$label" {{ $attributes }} />
</div>
