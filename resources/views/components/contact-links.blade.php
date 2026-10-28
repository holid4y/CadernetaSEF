@props(['client', 'message' => '', 'subject' => 'Atendimento na Agenda Contábil'])
@php
    $email = App\Support\ContactLinks::email($client->email, $subject, $message);
    $gmail = App\Support\ContactLinks::gmail($client->email, $subject, $message);
    $whatsapp = App\Support\ContactLinks::whatsapp($client->phone, $message);
@endphp
<div class="space-y-2">
    <div class="flex flex-wrap gap-2">
        @if ($email)
            <a href="{{ $gmail }}" target="_blank" rel="noopener noreferrer" class="press inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm font-semibold">Escrever no Gmail</a>
            <a href="{{ $email }}" class="press inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm font-semibold">Outro programa de e-mail</a>
        @else
            <p class="text-sm text-ink-muted">Cadastre um e-mail válido para escrever ao atendido.</p>
        @endif
        @if ($whatsapp)
            <a href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" class="press inline-flex min-h-11 items-center rounded-full bg-moss-soft px-4 py-2 text-sm font-semibold text-moss">WhatsApp</a>
        @else
            <p class="text-sm text-ink-muted">Cadastre um telefone válido com DDD para abrir o WhatsApp.</p>
        @endif
        @if ($message !== '')
            <button type="button" x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($message)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                    class="press inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 py-2 text-sm font-semibold">
                <span x-text="copied ? 'Mensagem copiada' : 'Copiar mensagem'">Copiar mensagem</span>
            </button>
        @endif
    </div>
    <p class="text-sm text-ink-muted">Você confere a mensagem e envia pela sua conta. Abrir o e-mail não confirma o envio.</p>
</div>
