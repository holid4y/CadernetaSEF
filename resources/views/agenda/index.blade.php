@php($canCreate = auth()->user()->can('create', App\Models\Appointment::class))

<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('agenda.title')" :subtitle="$canCreate ? __('agenda.subtitle') : __('agenda.subtitle_readonly')">
            @if ($canCreate)
                <x-slot name="actions">
                    @if ($google && $google['account'] === null)
                        <x-google-button :href="route('google.connect')">{{ __('google.continue') }}</x-google-button>
                    @elseif ($google)
                        <x-dropdown width="w-60">
                            <x-slot name="trigger">
                                <button type="button" class="google-button press" aria-haspopup="menu">
                                    <span class="google-button__mark"><x-google-logo /></span>
                                    <span>{{ __('google.title') }}</span>
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="App\Domain\Google\GoogleCalendar::WEB_URL" target="_blank" rel="noopener noreferrer">{{ __('google.open') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('google.show')" wire:navigate>Configurar conexão</x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    @endif
                    <x-button-link :href="route('appointments.create')">{{ __('agenda.new') }}</x-button-link>
                </x-slot>
            @endif
        </x-page-header>

        <x-flash-status />

        @if ($waitingFromGoogle > 0)
            <p class="flex items-center gap-2 rounded-soft border border-dashed border-line px-4 py-3 text-sm text-ink-muted">
                <x-google-logo />
                {{ trans_choice('agenda.waiting_from_google', $waitingFromGoogle, ['count' => $waitingFromGoogle]) }}
            </p>
        @endif

        @if ($google && $google['account']?->failed_at)
            <p class="rounded-soft bg-danger-soft px-4 py-3 text-sm text-danger">
                O Google não aceita mais a conexão da sua conta.
                <a href="{{ route('google.connect') }}" class="font-semibold underline underline-offset-4">Conecte de novo</a>.
            </p>
        @endif

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
