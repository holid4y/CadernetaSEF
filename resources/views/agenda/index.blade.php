@php($canCreate = auth()->user()->can('create', App\Models\Appointment::class))

<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('agenda.title')" :subtitle="$canCreate ? __('agenda.subtitle') : __('agenda.subtitle_readonly')">
            @if ($canCreate)
                <x-slot name="actions">
                    <x-button-link :href="route('appointments.create')">{{ __('agenda.new') }}</x-button-link>
                </x-slot>
            @endif
        </x-page-header>

        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <label for="agenda-responsible" class="text-sm text-ink-muted">{{ __('agenda.filter_responsible') }}</label>
            <x-select-input id="agenda-responsible" data-agenda-filter="responsible">
                <option value="">{{ __('agenda.everyone') }}</option>
                @foreach ($responsibles as $responsible)
                    <option value="{{ $responsible->id }}">{{ $responsible->name }}</option>
                @endforeach
            </x-select-input>

            <label class="inline-flex min-h-11 items-center gap-2 text-sm text-ink-muted">
                <x-checkbox-input data-agenda-filter="cancelled" />
                {{ __('agenda.show_cancelled') }}
            </label>
        </div>

        <p data-agenda-message role="status" aria-live="polite" class="hidden"></p>

        <div class="rise-in min-w-0 overflow-hidden rounded-[1.75rem] border border-line bg-surface p-3 sm:p-6">
            <ul class="mb-4 flex flex-wrap gap-1.5" aria-label="{{ __('agenda.legend') }}">
                @foreach (App\Domain\Appointments\Enums\AppointmentStatus::cases() as $status)
                    <li><x-status-badge :status="$status" /></li>
                @endforeach
            </ul>

            <div
                data-agenda
                data-timezone="{{ App\Support\DisplayTimezone::name() }}"
                data-events-url="{{ route('agenda.events') }}"
                data-create-url="{{ $canCreate ? route('appointments.create') : '' }}"
                data-reschedule-url="{{ route('appointments.reschedule', ['appointment' => '__ID__']) }}"
                data-text-saving="{{ __('agenda.saving') }}"
                data-text-saved="{{ __('agenda.saved') }}"
                data-text-failed="{{ __('agenda.failed') }}"
                data-text-empty="{{ __('agenda.empty') }}"
            ></div>
        </div>
    </div>
</x-app-layout>
