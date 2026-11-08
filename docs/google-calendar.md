# Google Agenda

Cada pessoa da equipe (administrador ou membro) pode ligar a própria conta
Google à Caderneta, pela web (Agenda → “Continuar com o Google”, ou em
`/google`) ou pelo app (Perfil → Google Agenda). Visualizadores não conectam.

Depois de conectar:

- Os atendimentos em que a pessoa é responsável aparecem numa agenda chamada
  “Caderneta SEF”, criada pelo sistema na conta dela. Marcar, remarcar, trocar o
  responsável ou cancelar muda essa agenda em segundos. O título do evento é
  “Serviço · Nome do atendido”; a descrição leva só o link para o atendimento.
  CPF/CNPJ, telefone, e-mail e observações não saem da Caderneta.
- A pessoa escolhe uma das agendas da conta como fonte. A cada cinco minutos o
  servidor lê os eventos dela, de ontem até 180 dias à frente, inclusive os
  criados depois. Cada evento com horário entra na agenda da Caderneta como
  “a completar”, com o logo do Google, e ocupa o horário. Eventos de dia
  inteiro, aniversários, “fora do escritório”, foco e localização ficam de fora,
  assim como eventos de mais de 12 horas.
- Clicando no evento, abre o formulário de novo atendimento com data, horário e
  local já preenchidos. Escolhidos atendido e serviço, ele vira atendimento e não
  pode virar outro. “Não é um atendimento” tira o evento da Caderneta (no Google
  ele continua).
- Se o horário de um evento já convertido mudar no Google, o atendimento é
  remarcado. Se o novo horário bater com outro atendimento do responsável, nada
  muda e o atendimento mostra o aviso. Se o evento for apagado no Google, o
  atendimento só mostra o aviso; quem cancela é a equipe.

Desconectar apaga a agenda “Caderneta SEF” da conta e revoga o acesso no Google.
Os atendimentos continuam na Caderneta. Desativar uma conta com
`users:deactivate` faz o mesmo.

## Configuração no Google Cloud

Precisa ser feita uma vez, por quem administra o projeto, com uma conta Google
da SEF.

1. Em https://console.cloud.google.com crie um projeto (por exemplo
   “Caderneta SEF”).
2. Em APIs e serviços → Biblioteca, ative a Google Calendar API.
3. Em APIs e serviços → Tela de consentimento OAuth (Google Auth Platform):
   tipo de usuário Externo, nome do app “Caderneta SEF”, e-mail de suporte, logo
   opcional, domínio autorizado `cadernetasef.com.br`, página inicial
   `https://cadernetasef.com.br` e política de privacidade
   `https://cadernetasef.com.br/privacidade`.
4. Em Acesso a dados, adicione os escopos:
   `openid`, `.../auth/userinfo.email`, `.../auth/calendar.app.created`,
   `.../auth/calendar.calendarlist.readonly` e `.../auth/calendar.events.readonly`.
5. Em Clientes, crie um ID do cliente OAuth do tipo Aplicativo da Web com o URI
   de redirecionamento autorizado exatamente igual a
   `https://cadernetasef.com.br/google/retorno`.
6. Copie o ID e a chave secreta para o `.env` do servidor:

   ```dotenv
   GOOGLE_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=...
   ```

   Depois, `php artisan config:cache` e recarregue o PHP-FPM
   ([operations.md](operations.md)). Sem os dois valores, o botão do Google não
   aparece em lugar nenhum.

### Status de publicação

Enquanto o app estiver em “Teste” no Google, só os e-mails cadastrados como
usuários de teste conseguem conectar, e o Google invalida o acesso depois de
sete dias: a pessoa vê “O Google não aceita mais a conexão” e precisa conectar
de novo. Para uso real, publique o app (“Em produção”). Os escopos de agenda
são sensíveis, então, até o Google verificar o app, quem conecta vê o aviso
“O Google não verificou este app” e precisa tocar em Avançado → Acessar. Esse
modo atende até 100 pessoas, o que cobre a equipe.

### Verificação do app

Tira o aviso “O Google não verificou este app”. A análise dos escopos sensíveis
costuma levar alguns dias úteis; o Google responde por e-mail ao endereço de
contato do desenvolvedor.

1. Domínio. Em https://search.google.com/search-console adicione a propriedade
   do tipo Domínio `cadernetasef.com.br`, com a mesma conta Google dona do
   projeto no Cloud. Copie o registro TXT que ele mostrar e crie-o na
   Cloudflare (DNS → Records → Add record, tipo TXT, nome `@`). Depois de
   verificado, o domínio aparece como autorizado na tela de consentimento.
2. Branding (Google Auth Platform → Branding): página inicial
   `https://cadernetasef.com.br` (pública, explica o sistema e tem link para a
   política), política `https://cadernetasef.com.br/privacidade`, e-mail de
   suporte e contato do desenvolvedor. O logo é opcional e, se enviado, também
   passa por análise.
3. Público: status “Em produção”.
4. Acesso a dados: para cada escopo sensível, a justificativa (em inglês):

   ```text
   calendar.app.created — Each team member's appointments are kept in a
   secondary calendar named "Caderneta SEF" that the app creates in their
   account. The app only writes to that calendar.

   calendar.calendarlist.readonly — The team member picks, from the list of
   their calendars, the one the project already uses for appointments, so
   its events can be brought into the app.

   calendar.events.readonly — Events of the calendar the member picked are
   read and shown in the app as appointments to complete (client and
   service). Nothing is written to that calendar.
   ```

5. Vídeo de demonstração (YouTube, não listado), em inglês ou com legenda:
   tela inicial com o link da política; login na Caderneta; Google Agenda →
   Conectar, mostrando a tela de consentimento com o ID do cliente na barra de
   endereço; a agenda “Caderneta SEF” criada no Google com um atendimento; a
   escolha da agenda de origem e um evento dela aparecendo como “a completar”;
   e Desconectar.
6. Envie para verificação em Central de verificação.

## Como funciona por dentro

- O login no Google usa `state` aleatório e PKCE (S256), guardados na sessão por
  dez minutos e usados uma vez só. O retorno confere se quem terminou é quem
  começou e se todas as permissões foram marcadas; se faltar alguma, o acesso é
  revogado na hora.
- O app não abre o Google dentro dele (o Google bloqueia login em WebView). Ele
  pede ao servidor um link de uso único, válido por cinco minutos, e abre no
  navegador do celular. Só o hash do link fica guardado. A página final não faz
  login na Caderneta; ao voltar, o app recarrega e mostra a conta conectada.
- Os tokens ficam em `google_accounts`, cifrados com a `APP_KEY`, nunca no app,
  em URLs ou nos logs. O papel `agenda_reports` não lê nenhuma tabela do Google;
  `verify.sql` confere isso.
- O envio roda na fila (`SyncAppointmentToGoogle`), depois do commit, e lê o
  atendimento como ele está na hora de rodar. O ID do evento no Google é o
  `integration_id` do atendimento sem hífens, então repetir o envio não duplica
  o evento.
- A leitura roda pelo agendador (`google:pull`, a cada cinco minutos), um job por
  conta, sem sobreposição. Eventos do Google que viraram atendimento ficam em
  `google_events` com o `appointment_id`; os que sumiram da agenda antes disso são
  apagados daqui.
- Links vindos do Google só viram `href` se forem HTTPS em `calendar.google.com`
  ou `www.google.com`. Títulos e locais são sempre mostrados como texto.
- Se o Google responder que o acesso acabou (`invalid_grant`), a conta fica
  marcada e a web e o app pedem para conectar de novo; nada é tentado até lá.

## Por que assim

- Escopos estreitos em vez de `calendar`: o sistema só escreve na agenda que ele
  mesmo criou (`calendar.app.created`) e só lê a agenda escolhida. Uma falha na
  Caderneta não alcança o resto da agenda da pessoa.
- Eventos do Google não viram atendimentos sozinhos: um evento não traz atendido
  nem serviço, e cada responsável tem no máximo cinco atendimentos ao mesmo tempo.
  “A completar” mantém tudo visível sem criar atendidos falsos.
- A cópia do atendimento não volta para a agenda de onde o evento veio: quem
  importou já tem o evento original na conta, e o sistema não tem (nem pede)
  permissão para escrever nela.
- Leitura periódica em vez de notificações do Google: não depende de URL pública
  registrada nem de renovar inscrições, e cinco minutos bastam para a agenda.

## Problemas comuns

| Sintoma | O que fazer |
| --- | --- |
| “redirect_uri_mismatch” no Google | O URI do cliente OAuth precisa ser exatamente `APP_URL/google/retorno`, com `https`. |
| “Para conectar, marque todas as permissões” | A pessoa desmarcou uma permissão na tela do Google. É só conectar de novo marcando todas. |
| “O Google não aceita mais a conexão” a cada semana | O app está em “Teste” no Google Cloud; publique-o. |
| Eventos não aparecem | Confira se a fila e o agendador estão ativos (`systemctl status caderneta-queue`, `systemctl list-timers caderneta-scheduler.timer`) e se a pessoa escolheu uma agenda em `/google`. |
| Erros na fila | `storage/logs/laravel.log` e `php artisan queue:failed`. Os logs trazem só o ID da conta e o tipo do erro. |
