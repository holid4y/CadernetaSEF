<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('google.title')" subtitle="Seus atendimentos aparecem na sua Google Agenda, e os eventos de uma agenda que você escolher entram na Caderneta." />

        <x-flash-status />
        <x-input-error :messages="$errors->get('google')" />

        @if (! $configured)
            <x-card>
                <p class="text-ink-muted">{{ __('google.errors.not_configured') }}</p>
            </x-card>
        @elseif ($account === null)
            <x-card class="space-y-4">
                <h2 class="text-2xl">Conectar sua conta</h2>
                <ul class="list-disc space-y-1 ps-5 text-sm text-ink-muted">
                    <li>Os atendimentos em que você é responsável vão para uma agenda chamada “Caderneta SEF”, criada na sua conta. Remarcar ou cancelar aqui muda lá também.</li>
                    <li>Depois você escolhe uma agenda do Google para trazer para cá. Os eventos dela aparecem na agenda como “a completar”.</li>
                    <li>O Google vai pedir permissão para ver seu e-mail, criar essa agenda, listar suas agendas e ler os eventos da que você escolher.</li>
                </ul>
                <x-google-button :href="route('google.connect')">{{ __('google.continue') }}</x-google-button>
            </x-card>
        @else
            <x-card class="space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <x-google-logo />
                    <div class="min-w-0">
                        <p class="font-semibold break-words">{{ $account->email }}</p>
                        <p class="text-sm text-ink-muted">
                            @if ($account->failed_at)
                                O Google não aceita mais esta conexão. Conecte de novo para voltar a sincronizar.
                            @elseif ($account->pulled_at)
                                Última leitura do Google às {{ App\Support\DisplayTimezone::toLocal($account->pulled_at)->format('H:i \d\e d/m') }}.
                            @else
                                Conectada.
                            @endif
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($account->failed_at)
                        <x-google-button :href="route('google.connect')">{{ __('google.continue') }}</x-google-button>
                    @endif
                    <a href="{{ App\Domain\Google\GoogleCalendar::WEB_URL }}" target="_blank" rel="noopener noreferrer" class="press inline-flex min-h-11 items-center gap-2 rounded-full border border-line px-4 text-sm font-semibold text-primary">
                        <x-google-logo /> {{ __('google.open') }}
                    </a>
                </div>
            </x-card>

            @if (! $account->failed_at)
                <x-card class="space-y-4">
                    <h2 class="text-2xl">Agenda que vem para a Caderneta</h2>
                    <p class="text-sm text-ink-muted">Cada evento com horário dessa agenda aparece na Caderneta em até cinco minutos, inclusive os que forem criados depois. Eventos de dia inteiro, aniversários e ausências não entram.</p>

                    @if ($calendars === null)
                        <p class="text-sm text-danger">{{ $calendarsError }}</p>
                    @else
                        <form method="POST" action="{{ route('google.update') }}" class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <x-input-label for="google-calendar" value="Agenda" />
                            <x-select-input id="google-calendar" name="calendar" class="block w-full">
                                <option value="">Não trazer eventos do Google</option>
                                @foreach ($calendars as $calendar)
                                    <option value="{{ $calendar['id'] }}" @selected($calendar['id'] === $account->source_calendar_id)>
                                        {{ $calendar['name'] }}{{ $calendar['primary'] ? ' (principal)' : '' }}
                                    </option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('calendar')" />
                            <x-primary-button>Salvar</x-primary-button>
                        </form>
                    @endif
                </x-card>
            @endif

            <x-card class="space-y-3">
                <h2 class="text-2xl">Desconectar</h2>
                <p class="text-sm text-ink-muted">A agenda “Caderneta SEF” é apagada da sua conta Google e a Caderneta perde o acesso. Os atendimentos continuam aqui.</p>
                <form method="POST" action="{{ route('google.destroy') }}" x-data x-on:submit="confirm('Desconectar a conta Google?') || $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="press inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold text-danger hover:bg-danger-soft">Desconectar</button>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>
