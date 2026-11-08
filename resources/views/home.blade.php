<x-public-layout>
    <article class="space-y-10 leading-relaxed">
        <header class="space-y-5">
            <h1 class="text-4xl leading-[1.05] tracking-tight sm:text-5xl">Caderneta SEF</h1>
            <p class="text-lg text-ink-muted">
                A agenda dos atendimentos do projeto de extensão Sustentabilidade Econômica e Financeira,
                do curso de Ciências Contábeis da Universidade Feevale. A equipe do projeto marca, acompanha
                e registra os atendimentos gratuitos de contabilidade e finanças para a comunidade.
            </p>
            <div class="flex flex-wrap gap-3">
                <x-button-link :href="route('login')">Entrar</x-button-link>
                <x-button-link :href="route('privacy')" variant="secondary">Política de privacidade</x-button-link>
            </div>
            <p class="text-sm text-ink-muted">Só a equipe do projeto tem conta; não há cadastro público.</p>
        </header>

        <section class="space-y-2">
            <h2 class="text-2xl">O que a equipe faz por aqui</h2>
            <ul class="list-disc space-y-1 ps-6">
                <li>marca cada atendimento com dia, local e responsável;</li>
                <li>mostra os documentos que a pessoa precisa trazer e confere, no dia, o que ela trouxe;</li>
                <li>envia confirmação e lembrete a quem tem e-mail cadastrado e não pediu para ficar de fora;</li>
                <li>reúne os números do projeto para os relatórios.</li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="text-2xl">Google Agenda</h2>
            <p>
                Quem é da equipe pode ligar a própria conta Google. A Caderneta cria nela a agenda
                “Caderneta SEF” com os atendimentos da pessoa e lê os eventos da agenda que ela escolher,
                para trazê-los como atendimentos a completar. Nada é usado para publicidade nem repassado a
                terceiros; os detalhes estão na <a class="text-primary underline underline-offset-4" href="{{ route('privacy') }}">política de privacidade</a>.
            </p>
        </section>

        <section class="space-y-2">
            <h2 class="text-2xl">Atendimento</h2>
            <p>{{ __('brand.audience') }}. {{ __('brand.place') }}.</p>
            <p>
                Coordenação: {{ __('brand.coordinator') }},
                <a class="text-primary underline underline-offset-4" href="mailto:{{ __('brand.contact') }}">{{ __('brand.contact') }}</a>.
            </p>
        </section>
    </article>
</x-public-layout>
