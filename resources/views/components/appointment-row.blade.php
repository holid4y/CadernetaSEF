@props(['appointment', 'showDate' => false, 'showClient' => true])

@php
    $start = App\Support\DisplayTimezone::toLocal($appointment->starts_at);
    $end = App\Support\DisplayTimezone::toLocal($appointment->ends_at);
    $documents = $appointment->documents;
    $received = $documents->filter->isReceived()->count();
@endphp

<x-disclosure :id="'appointment-'.$appointment->id.'-details'"
              :label="__('appointments.row.details_of', ['name' => $appointment->client->name])">
    <x-slot name="summary">
        <span class="w-16 shrink-0 text-sm whitespace-nowrap sm:w-24">
            @if ($showDate)
                <span class="block font-semibold text-ink first-letter:uppercase">{{ $start->translatedFormat('D, d/m') }}</span>
            @endif
            <span @class(['block', 'text-ink-muted' => $showDate, 'font-semibold text-ink' => ! $showDate])>{{ $start->format('H:i') }}</span>
            <span class="block text-xs text-ink-muted">{{ $end->format('H:i') }}</span>
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-start justify-between gap-2">
                <span class="min-w-0 truncate font-medium text-ink">
                    {{ $showClient ? $appointment->client->name : $appointment->service->name }}
                </span>
                <span class="hidden sm:block"><x-status-badge :status="$appointment->status" /></span>
            </span>

            <span class="mt-0.5 flex min-w-0 items-center gap-2 text-sm text-ink-muted">
                <x-avatar :user="$appointment->responsible" />
                <span class="truncate">
                    {{ $showClient ? $appointment->service->name.' · ' : '' }}{{ $appointment->responsible->name }}
                </span>
            </span>

            <span class="mt-2 flex flex-wrap items-center gap-2 sm:mt-1">
                <span class="sm:hidden"><x-status-badge :status="$appointment->status" /></span>
                @if ($appointment->pending_documents_count > 0)
                    <span class="rounded-full bg-clay-soft px-2 py-0.5 text-xs text-clay">
                        {{ trans_choice('agenda.pending_documents', $appointment->pending_documents_count) }}
                    </span>
                @endif
            </span>
        </span>
    </x-slot>

    <div class="space-y-4 border-t border-line pt-4 text-sm">
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-ink-muted">{{ __('appointments.show.when') }}</dt>
                <dd class="text-ink first-letter:uppercase">{{ $appointment->weekday }}, {{ $start->translatedFormat('j \d\e F') }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }}</dd>
            </div>
            @if ($appointment->location_type || $appointment->location)
                <div>
                    <dt class="text-ink-muted">{{ __('appointments.show.where') }}</dt>
                    <dd class="text-ink">{{ collect([$appointment->location_type?->label(), $appointment->location])->filter()->implode(' · ') }}</dd>
                </div>
            @endif
            @if ($appointment->client->phone || $appointment->client->email)
                <div>
                    <dt class="text-ink-muted">{{ __('appointments.row.contact') }}</dt>
                    <dd class="flex flex-wrap gap-x-3">
                        @if ($appointment->client->phone)
                            <a href="tel:{{ preg_replace('/\D/', '', $appointment->client->phone) }}" class="text-primary underline underline-offset-4">{{ $appointment->client->phone }}</a>
                        @endif
                        @if ($appointment->client->email)
                            <a href="mailto:{{ $appointment->client->email }}" class="break-all text-primary underline underline-offset-4">{{ $appointment->client->email }}</a>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        @if ($documents->isNotEmpty())
            <div>
                <p class="text-ink-muted">{{ __('appointments.show.documents') }} · {{ __('appointments.show.documents_progress', ['received' => $received, 'total' => $documents->count()]) }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($documents as $document)
                        <li class="flex items-start gap-2">
                            <span @class([
                                'mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full text-[0.6rem]',
                                'bg-moss-soft text-moss' => $document->isReceived(),
                                'border border-line' => ! $document->isReceived(),
                            ]) aria-hidden="true">{{ $document->isReceived() ? '✓' : '' }}</span>
                            <span @class(['text-ink', 'text-ink-muted line-through' => $document->isReceived()])>
                                {{ $document->name }}
                                <span class="sr-only">— {{ $document->isReceived() ? __('appointments.row.received') : __('appointments.row.pending') }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($appointment->notes && auth()->user()->canWrite())
            <p class="whitespace-pre-line text-ink-muted">{{ $appointment->notes }}</p>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-button-link :href="route('appointments.show', $appointment)" size="sm">{{ __('appointments.row.open') }}</x-button-link>
            @if ($showClient)
                <x-button-link variant="secondary" :href="route('clients.show', $appointment->client)" size="sm">{{ __('appointments.show.client_page') }}</x-button-link>
            @endif
        </div>
    </div>
</x-disclosure>
