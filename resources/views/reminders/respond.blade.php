@php
    $canConfirm = in_array(App\Domain\Appointments\Enums\AppointmentStatus::Confirmed, $answers, true);
    $canCancel = in_array(App\Domain\Appointments\Enums\AppointmentStatus::Cancelled, $answers, true);
@endphp

<x-public-layout>
    <x-flash-status class="mb-6" />

    <p class="text-ink-muted">{{ __('reminders.page.hello', ['name' => $appointment->client->greetingName()]) }}</p>
    <h1 class="mt-2 text-3xl sm:text-4xl first-letter:uppercase">{{ $appointment->weekday }}, {{ $start->translatedFormat('j \d\e F') }}</h1>
    <p class="mt-1 text-xl text-ink">{{ $start->format('H:i') }} – {{ $end->format('H:i') }}</p>

    <dl class="mt-8 divide-y divide-line border-y border-line text-sm">
        <div class="grid gap-1 py-4 sm:grid-cols-[8rem_1fr] sm:gap-6">
            <dt class="text-ink-muted">{{ __('reminders.page.service') }}</dt>
            <dd class="text-ink">{{ $appointment->service->name }}</dd>
        </div>
        @if ($appointment->location)
            <div class="grid gap-1 py-4 sm:grid-cols-[8rem_1fr] sm:gap-6">
                <dt class="text-ink-muted">{{ __('reminders.page.where') }}</dt>
                <dd class="text-ink">{{ $appointment->location }}</dd>
            </div>
        @endif
        <div class="grid gap-1 py-4 sm:grid-cols-[8rem_1fr] sm:gap-6">
            <dt class="text-ink-muted">{{ __('reminders.page.status') }}</dt>
            <dd><x-status-badge :status="$appointment->status" /></dd>
        </div>
    </dl>

    @if ($appointment->documents->isNotEmpty())
        <h2 class="mt-10 text-xl">{{ __('reminders.documents') }}</h2>
        <ul class="mt-3 space-y-2 text-ink">
            @foreach ($appointment->documents as $document)
                <li class="flex gap-3">
                    <span class="mt-2 size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                    {{ $document->name }}
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canConfirm || $canCancel)
        <div class="mt-10 flex flex-col gap-3 border-t border-line pt-8 sm:flex-row">
            @if ($canConfirm)
                <form method="POST" action="{{ request()->fullUrl() }}">
                    @csrf
                    <input type="hidden" name="answer" value="confirm">
                    <x-primary-button class="w-full sm:w-auto">{{ __('reminders.page.confirm') }}</x-primary-button>
                </form>
            @endif
            @if ($canCancel)
                <form method="POST" action="{{ request()->fullUrl() }}" x-data
                      x-on:submit="confirm(@js(__('reminders.page.cancel_confirm'))) || $event.preventDefault()">
                    @csrf
                    <input type="hidden" name="answer" value="cancel">
                    <x-secondary-button type="submit" class="w-full sm:w-auto">{{ __('reminders.page.cancel') }}</x-secondary-button>
                </form>
            @endif
        </div>
    @endif
</x-public-layout>
