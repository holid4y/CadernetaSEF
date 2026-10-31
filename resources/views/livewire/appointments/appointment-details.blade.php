@php
    $canUpdate = auth()->user()->can('update', $appointment);
    $documents = $appointment->documents;
    $received = $documents->filter->isReceived()->count();
    $formAction = App\Support\FormLinks::action($appointment, $canUpdate);
@endphp

<div class="space-y-6">
    @if ($message)
        <div wire:key="message-{{ md5($message) }}" class="rise-in rounded-soft bg-moss-soft px-4 py-3 text-sm text-moss" role="status">{{ $message }}</div>
    @endif

    <x-input-error :messages="$errors->get('status')" />

    <div class="rise-in space-y-5 rounded-[1.75rem] bg-sand/70 p-6 sm:p-8">
        <div>
            <x-status-badge :status="$appointment->status" />
            <h1 class="mt-3 text-3xl sm:text-4xl">{{ $appointment->client->name }}</h1>
            <p class="mt-2 text-ink-muted">
                {{ $appointment->service->category->name }} · {{ $appointment->service->name }}
            </p>
            <a href="{{ route('clients.show', $appointment->client) }}" wire:navigate class="mt-2 inline-block text-sm text-primary underline underline-offset-4">
                {{ __('appointments.show.client_page') }}
            </a>
        </div>

        @if ($formAction)
            <a href="{{ $formAction['url'] }}" target="_blank" rel="noopener noreferrer" data-form-action
               class="press inline-flex min-h-11 items-center gap-2 rounded-full border border-line bg-surface px-4 text-sm font-semibold text-primary hover:bg-sunken">
                {{ $formAction['label'] }}
            </a>
        @endif

        @if ($canUpdate && $appointment->status->allowedTransitions())
            <div class="flex flex-wrap gap-2">
                @foreach ($appointment->status->allowedTransitions() as $next)
                    @php
                        $confirmation = match ($next) {
                            App\Domain\Appointments\Enums\AppointmentStatus::Cancelled => __('appointments.show.confirm_cancel'),
                            App\Domain\Appointments\Enums\AppointmentStatus::NoShow => __('appointments.show.confirm_no_show'),
                            default => null,
                        };
                        $primary = $loop->first && $confirmation === null;
                    @endphp

                    <button type="button"
                            wire:click="changeStatus('{{ $next->value }}')"
                            @if ($confirmation) wire:confirm="{{ $confirmation }}" @endif
                            @class([
                                'press inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold',
                                'bg-primary text-on-primary hover:bg-primary-hover' => $primary,
                                'border border-line bg-surface text-ink hover:bg-sunken' => ! $primary && $confirmation === null,
                                'text-danger hover:bg-danger-soft' => $confirmation !== null,
                            ])>
                        {{ $next->actionLabel() }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[3fr_2fr]">
        <div class="space-y-6">
            <x-card>
                @if ($editing_schedule)
                    <form wire:submit="saveSchedule" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <x-input-label for="date" :value="__('appointments.fields.date')" />
                                <x-text-input id="date" type="date" wire:model="date" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <x-input-label for="start_time" :value="__('appointments.fields.start_time')" />
                                <x-text-input id="start_time" type="time" step="900" wire:model="start_time" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <x-input-label for="end_time" :value="__('appointments.fields.end_time')" />
                                <x-text-input id="end_time" type="time" step="900" wire:model="end_time" class="mt-1 block w-full" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="responsible_user_id" :value="__('appointments.fields.responsible')" />
                            <x-select-input id="responsible_user_id" wire:model="responsible_user_id" class="mt-1 block w-full">
                                @foreach ($this->responsibles as $responsible)
                                    <option value="{{ $responsible->id }}">{{ $responsible->name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <x-input-error :messages="array_merge($errors->get('starts_at'), $errors->get('ends_at'), $errors->get('responsible_user_id'), $errors->get('date'), $errors->get('start_time'), $errors->get('end_time'))" />
                        <div class="flex flex-wrap gap-3">
                            <x-primary-button>{{ __('appointments.show.save_schedule') }}</x-primary-button>
                            <x-secondary-button wire:click="$set('editing_schedule', false)">{{ __('appointments.show.cancel_edit') }}</x-secondary-button>
                        </div>
                    </form>
                @else
                    <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm text-ink-muted">{{ __('appointments.show.when') }}</dt>
                            <dd class="mt-1 text-lg text-ink first-letter:uppercase">{{ $appointment->weekday }}, {{ $start->translatedFormat('j \d\e F') }}</dd>
                            <dd class="text-ink-muted">{{ $start->format('H:i') }} – {{ $end->format('H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-ink-muted">{{ __('appointments.show.responsible') }}</dt>
                            <dd class="mt-1 flex items-center gap-2 text-lg text-ink"><x-avatar :user="$appointment->responsible" class="size-7" />{{ $appointment->responsible->name }}</dd>
                        </div>
                        @if ($appointment->location_type || $appointment->location)
                            <div>
                                <dt class="text-sm text-ink-muted">{{ __('appointments.show.where') }}</dt>
                                <dd class="mt-1 text-ink">{{ collect([$appointment->location_type?->label(), $appointment->location])->filter()->implode(' · ') }}</dd>
                            </div>
                        @endif
                        @if ($appointment->service_details)
                            <div class="sm:col-span-2">
                                <dt class="text-sm text-ink-muted">{{ __('appointments.fields.service_details') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-ink">{{ $appointment->service_details }}</dd>
                            </div>
                        @endif
                        {{-- Viewers do not get CPF/CNPJ or notes, on the web or in the app. --}}
                        @if ($appointment->notes && auth()->user()->canWrite())
                            <div class="sm:col-span-2">
                                <dt class="text-sm text-ink-muted">{{ __('appointments.show.notes') }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-ink">{{ $appointment->notes }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($canUpdate && $appointment->status->canBeRescheduled())
                        <button type="button" wire:click="editSchedule" class="mt-6 min-h-11 text-sm text-primary underline underline-offset-4">
                            {{ __('appointments.show.edit_schedule') }}
                        </button>
                    @endif
                @endif
            </x-card>

            <x-card>
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-2xl">{{ __('appointments.show.documents') }}</h2>
                    @if ($documents->isNotEmpty())
                        <p class="text-sm text-ink-muted">{{ __('appointments.show.documents_progress', ['received' => $received, 'total' => $documents->count()]) }}</p>
                    @endif
                </div>

                @if ($documents->isEmpty())
                    <p class="mt-4 text-sm text-ink-muted">{{ __('appointments.show.documents_empty') }}</p>
                @else
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-moss-soft" aria-hidden="true">
                        <div class="h-full rounded-full bg-moss transition-[width] duration-500 ease-out" style="width: {{ round($received / $documents->count() * 100) }}%"></div>
                    </div>

                    <ul class="-mx-3 mt-4 space-y-1">
                        @foreach ($documents as $document)
                            <li wire:key="document-{{ $document->id }}" x-data="{ received: @js($document->isReceived()) }">
                                @if ($canUpdate)
                                    <button type="button" role="checkbox"
                                            x-bind:aria-checked="received.toString()"
                                            x-on:click="received = ! received; $wire.markDocument({{ $document->id }}, received)"
                                            class="press flex min-h-12 w-full items-center gap-3 rounded-soft px-3 py-2 text-start"
                                            x-bind:class="received ? 'bg-moss-soft' : 'hover:bg-sunken'">
                                        @include('livewire.appointments.partials.document-state', ['name' => $document->name])
                                    </button>
                                @else
                                    <div class="flex min-h-12 items-center gap-3 px-3 py-2" x-bind:class="received && 'bg-moss-soft rounded-soft'">
                                        @include('livewire.appointments.partials.document-state', ['name' => $document->name])
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <div class="space-y-6 self-start">
            <x-appointment-forms :appointment="$appointment" />
            <x-google-calendar-card :appointment="$appointment" />
            @if ($appointment->status->canBeRescheduled())
                <x-card>
                    <h2 class="text-2xl">{{ __('reminders.card.title') }}</h2>
                    <p class="mt-2 text-sm text-ink-muted">
                        {{ match (true) {
                            ! $appointment->client->active => 'Este atendido está inativo.',
                            ! $appointment->client->accepts_reminders => __('reminders.card.no_consent'),
                            $appointment->reminder_sent_at !== null => __('reminders.card.sent_at', ['date' => App\Support\DisplayTimezone::toLocal($appointment->reminder_sent_at)->format('d/m \à\s H:i')]),
                            ! config('mail.reminders_enabled') => __('reminders.errors.disabled'),
                            App\Support\ContactLinks::email($appointment->client->email) === null => 'Cadastre um e-mail válido para o lembrete automático.',
                            default => __('reminders.card.scheduled'),
                        } }}
                    </p>

                    @if ($canUpdate && $appointment->client->active && $appointment->client->accepts_reminders)
                        <div class="mt-4">
                            <x-contact-links :client="$appointment->client" :message="App\Domain\Appointments\ReminderMessage::text($appointment)" />
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($appointment->client->canReceiveReminders() && config('mail.reminders_enabled'))
                                <x-secondary-button wire:click="sendReminder" wire:loading.attr="disabled" wire:target="sendReminder">
                                    {{ $appointment->reminder_sent_at ? 'Reenviar pelo servidor' : 'Enviar pelo servidor' }}
                                </x-secondary-button>
                            @endif

                        </div>
                    @endif
                </x-card>
            @endif

            <x-card>
                <h2 class="text-2xl">{{ __('appointments.show.history') }}</h2>
                <ol class="mt-4 space-y-4 border-s border-line ps-5">
                    @foreach ($appointment->activities as $activity)
                        @php
                            $dot = match ($activity->event_type) {
                                App\Domain\Appointments\Enums\ActivityType::Created => 'bg-primary',
                                App\Domain\Appointments\Enums\ActivityType::Rescheduled => 'bg-ochre',
                                App\Domain\Appointments\Enums\ActivityType::StatusChanged => 'bg-moss',
                                App\Domain\Appointments\Enums\ActivityType::ResponsibleChanged => 'bg-plum',
                                App\Domain\Appointments\Enums\ActivityType::Cancelled => 'bg-danger',
                            };
                        @endphp
                        <li class="relative text-sm" wire:key="activity-{{ $activity->id }}">
                            <span class="absolute -start-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-surface {{ $dot }}" aria-hidden="true"></span>
                            <p class="font-medium text-ink">{{ $activity->event_type->label() }}</p>
                            @php($change = $activity->changeSummary($usersById))
                            @if ($change)
                                <p class="text-ink-muted">{{ $change }}</p>
                            @endif
                            <p class="text-xs text-ink-muted">
                                {{ App\Support\DisplayTimezone::toLocal($activity->created_at)->format('d/m/Y H:i') }}
                                · {{ $activity->user ? __('appointments.show.by', ['name' => $activity->user->name]) : __('appointments.show.by_client') }}
                            </p>
                        </li>
                    @endforeach
                </ol>
            </x-card>
        </div>
    </div>
</div>
