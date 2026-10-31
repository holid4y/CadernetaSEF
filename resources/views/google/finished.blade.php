<x-public-layout>
    <div class="space-y-4">
        <x-google-logo size="40" />
        @if ($error)
            <h1 class="text-3xl">Não deu para conectar</h1>
            <p class="text-ink-muted">{{ $error }}</p>
        @else
            <h1 class="text-3xl">Google Agenda conectada</h1>
            <p class="text-ink-muted">A conta Google {{ $email }} está ligada à Caderneta de {{ $name }}. Pode fechar esta página e voltar para o app.</p>
            <p class="text-sm text-ink-muted">Se você não é {{ $name }}, desconecte a conta em myaccount.google.com/connections e avise a coordenação.</p>
        @endif
    </div>
</x-public-layout>
