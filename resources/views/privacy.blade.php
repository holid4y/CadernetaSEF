<x-public-layout>
    <article class="space-y-6 leading-relaxed">
        <header>
            <h1 class="text-4xl">Política de privacidade</h1>
            <p class="mt-2 text-ink-muted">Caderneta SEF · atualizada em 31/10/2026</p>
        </header>

        <section class="space-y-2">
            <h2 class="text-2xl">Quem somos</h2>
            <p>A Caderneta SEF é a agenda dos atendimentos do projeto de extensão Sustentabilidade Econômica e Financeira, do curso de Ciências Contábeis da Universidade Feevale. Só a equipe do projeto tem acesso ao sistema; não há cadastro público.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-2xl">Dados das pessoas atendidas</h2>
            <p>Guardamos nome, CPF ou CNPJ, telefone, e-mail, os atendimentos marcados e os documentos pedidos para cada um. CPF/CNPJ e observações ficam criptografados. Esses dados servem só para organizar os atendimentos e enviar lembretes a quem autorizou recebê-los.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-2xl">Google Agenda</h2>
            <p>Quem é da equipe pode ligar a própria conta Google à Caderneta. Com essa autorização, o sistema:</p>
            <ul class="list-disc space-y-1 ps-6">
                <li>lê o e-mail da conta, para mostrar qual conta está ligada;</li>
                <li>cria uma agenda chamada “Caderneta SEF” e mantém nela os atendimentos em que a pessoa é responsável, com o serviço e o nome do atendido;</li>
                <li>lista as agendas da conta e lê os eventos da agenda que a própria pessoa escolher, para mostrá-los na Caderneta como atendimentos a completar.</li>
            </ul>
            <p>Os dados recebidos do Google ficam no servidor do projeto, não são vendidos, não são usados para publicidade nem repassados a terceiros. O uso e a transferência de informações recebidas das APIs do Google seguem a <a class="text-primary underline underline-offset-4" href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener noreferrer">Política de Dados do Usuário dos Serviços de API do Google</a>, incluindo os requisitos de uso limitado.</p>
            <p>A conexão pode ser desfeita a qualquer momento na própria Caderneta (Google Agenda → Desconectar) ou em <a class="text-primary underline underline-offset-4" href="https://myaccount.google.com/connections" target="_blank" rel="noopener noreferrer">myaccount.google.com/connections</a>. Ao desconectar, a agenda “Caderneta SEF” é apagada e os tokens de acesso são descartados.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-2xl">Contato</h2>
            <p>Dúvidas, correções ou pedidos de exclusão de dados: {{ __('brand.coordinator') }}, <a class="text-primary underline underline-offset-4" href="mailto:{{ __('brand.contact') }}">{{ __('brand.contact') }}</a>.</p>
        </section>
    </article>
</x-public-layout>
