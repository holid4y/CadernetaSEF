<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <x-application-logo class="size-9 shrink-0 text-primary" />
    <span class="flex flex-col whitespace-nowrap leading-none">
        <span class="text-[0.7rem] uppercase tracking-[0.18em] text-ink-muted">{{ __('brand.kicker') }}</span>
        <span class="font-display text-lg text-ink [font-variation-settings:'SOFT'_100]">{{ __('brand.name') }}</span>
    </span>
</span>
