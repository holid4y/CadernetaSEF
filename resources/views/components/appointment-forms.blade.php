@props(['appointment'])

@php
    $enabled = config('services.forms.enabled');
    $createUrl = App\Support\FormLinks::create($appointment);
@endphp

<x-card class="rise-in min-w-0">
    <h2 class="text-xl">Formulários</h2>
    @if (! $enabled)
        <p class="mt-3 text-sm text-ink-muted">A integração com a plataforma de formulários ainda não foi configurada.</p>
    @else
        <label for="form-reference-{{ $appointment->id }}" class="mt-3 block text-sm text-ink-muted">Identificador para vincular o atendimento</label>
        <input id="form-reference-{{ $appointment->id }}" class="mt-2 w-full min-w-0 rounded-lg border-line bg-sunken text-xs" type="text" readonly value="{{ $appointment->integration_id }}" spellcheck="false">
        <p class="mt-2 text-xs text-ink-muted">A plataforma de formulários pode solicitar seu próprio login.</p>

        <ul class="mt-4 space-y-4">
            @forelse ($appointment->externalForms as $form)
                @php($openUrl = App\Support\FormLinks::open($form))
                <li class="min-w-0 border-t border-line pt-3">
                    <p class="break-words font-semibold">{{ $form->title }}</p>
                    <p class="mt-1 text-sm text-ink-muted">{{ $form->statusLabel() }}</p>
                    @if ($openUrl)
                        <a href="{{ $openUrl }}" target="_blank" rel="noopener noreferrer" class="press mt-2 inline-flex min-h-11 items-center rounded-full border border-line px-4 text-sm font-semibold text-primary">{{ $form->actionLabel() }}</a>
                    @endif
                    @if (! $openUrl && $form->status !== 'deleted')
                        <p class="mt-2 text-sm text-ink-muted">Link indisponível. Confira a configuração da integração.</p>
                    @endif
                </li>
            @empty
                <li class="text-sm text-ink-muted">Nenhum formulário vinculado a este atendimento.</li>
            @endforelse
        </ul>

        @can('update', $appointment)
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($createUrl)
                    <a href="{{ $createUrl }}" target="_blank" rel="noopener noreferrer" class="press inline-flex min-h-11 items-center rounded-full bg-primary px-4 text-sm font-semibold text-on-primary">Preencher formulário</a>
                @endif
                <a href="{{ route('forms.review', ['atendimento' => $appointment->integration_id]) }}" wire:navigate class="press inline-flex min-h-11 items-center rounded-full border border-line px-4 text-sm font-semibold text-primary">Revisar vínculos pendentes</a>
            </div>
        @endcan
    @endif
</x-card>
