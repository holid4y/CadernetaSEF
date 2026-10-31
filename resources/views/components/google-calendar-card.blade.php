@props(['appointment'])

@php
    $user = auth()->user();
    $account = App\Models\GoogleAccount::query()->where('user_id', $user->id)->first();
    $source = $appointment->googleEvent;
    $cancelled = $appointment->status === App\Domain\Appointments\Enums\AppointmentStatus::Cancelled;
    $link = ($account ? $appointment->googleEventCopies->firstWhere('google_account_id', $account->id)?->html_link : null)
        ?? ($source?->account->user_id === $user->id ? $source->html_link : null);
    $note = match (true) {
        $link !== null => null,
        $cancelled => 'Atendimentos cancelados saem da Google Agenda.',
        $account === null => 'Conecte sua conta para os atendimentos em que você é responsável aparecerem sozinhos na sua Google Agenda.',
        $appointment->responsible_user_id !== $user->id => 'Este atendimento aparece na Google Agenda de quem é responsável por ele.',
        default => 'A cópia na sua agenda “Caderneta SEF” fica pronta em instantes.',
    };
@endphp

@if (App\Domain\Google\GoogleAuthorization::configured() && $user->canWrite())
    <x-card class="space-y-3">
        <h2 class="flex items-center gap-2 text-2xl"><x-google-logo :size="22" /> {{ __('google.title') }}</h2>

        @if ($source)
            <p class="text-sm text-ink-muted">Veio da Google Agenda de {{ $source->account->user->name }}.</p>
        @endif

        @if ($source?->removed_at)
            <p class="text-sm text-danger">{{ __('google.errors.removed') }} Cancele aqui se o atendimento não vai acontecer.</p>
        @endif

        @if ($source?->sync_error)
            <p class="text-sm text-danger">
                No Google o horário passou para {{ App\Support\DisplayTimezone::toLocal($source->starts_at)->format('d/m H:i') }}–{{ App\Support\DisplayTimezone::toLocal($source->ends_at)->format('H:i') }}, mas não deu para mudar aqui: {{ $source->sync_error }}
            </p>
        @endif

        @if ($link)
            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="google-button press">
                <span class="google-button__mark"><x-google-logo /></span>
                <span>{{ __('google.open') }}</span>
            </a>
        @endif

        @if ($note)
            <p class="text-sm text-ink-muted">{{ $note }}</p>
        @endif

        @if ($link === null && $account === null && ! $cancelled)
            <x-google-button :href="route('google.connect')">{{ __('google.continue') }}</x-google-button>
        @endif
    </x-card>
@endif
