@props(['title', 'subtitle' => null])

<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h1 class="text-3xl sm:text-4xl">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-2 max-w-2xl text-ink-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-3">{{ $actions }}</div>
    @endisset
</div>
