@props(['wraps' => false])

{{-- Next to the theme toggle on a narrow phone, the name may take two lines. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <x-application-logo class="size-9 shrink-0 text-primary" />
    <span @class(['flex flex-col leading-none', 'whitespace-nowrap' => ! $wraps])>
        <span @class([
            'whitespace-nowrap text-[0.7rem] uppercase text-ink-muted',
            'tracking-[0.18em]' => ! $wraps,
            'tracking-[0.12em] sm:tracking-[0.18em]' => $wraps,
        ])>{{ __('brand.kicker') }}</span>
        <span @class([
            "font-display text-lg text-ink [font-variation-settings:'SOFT'_100]",
            'leading-tight sm:whitespace-nowrap sm:leading-none' => $wraps,
        ])>{{ __('brand.name') }}</span>
    </span>
</span>
