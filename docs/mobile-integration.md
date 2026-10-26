# Integração do app com o servidor

O app (Android, iOS e PWA) tem dois modos. No demo, os dados são fictícios e
ficam no aparelho; é o que o GitHub Pages publica. No servidor, o app autentica
no Laravel e toda leitura e gravação passa por `/api/v1`, nas mesmas regras e no
mesmo banco da web. Este documento descreve o modo servidor como está implementado.
O resumo do contrato também está em [`mobile/API.md`](../mobile/API.md).

```
app ── HTTPS, Authorization: Bearer <token> ──▶ /api/v1 (Laravel) ──▶ Actions, Policies ──▶ PostgreSQL
web ── sessão + CSRF ──────────────────────────▶ Livewire/controllers ─┘
```

## Onde está no código

| Parte | Arquivo |
| --- | --- |
| Rotas | `routes/api.php` |
| Login, logout | `app/Http/Controllers/Api/MobileSessionController.php` |
| Recuperação de senha | `app/Http/Controllers/Api/MobilePasswordResetController.php` |
| Fotografia do estado | `app/Http/Controllers/Api/MobileStateController.php`, `app/Http/Resources/Mobile/` |
| Comandos | `app/Http/Controllers/Api/MobileCommandController.php`, `app/Http/Requests/Api/MobileCommandRequest.php` |
| Regras usadas pelos comandos | `app/Domain/**/Actions`, as mesmas da web |
| Cliente no app | `mobile/js/api.js` (HTTP), `mobile/js/store.js` (estado e comandos) |
| Testes | `tests/Feature/Api/`, `tests/Feature/ConcurrentChangesTest.php`, `mobile/tests/server-*.test.js` |

## Autenticação

- `POST /api/v1/login` `{email, password, device_name}` → `{token, user}`. Token
  pessoal do Sanctum com validade de 12 horas (`SANCTUM_EXPIRATION`, em minutos).
- O token fica só na memória do app: fechar o app exige novo login, e nada vai
  para o `localStorage`.
- A senha é conferida pela mesma rotina do login web, com o mesmo bloqueio: 5
  erros por e-mail e IP, somados entre web e app. Além disso, cada IP tem 20
  tentativas por minuto (`429`) e o Nginx limita as rotas de login.
- Conta desativada não entra. Se for desativada durante o uso, a próxima chamada
  apaga todos os tokens dela e responde `401`.
- `POST /api/v1/logout` apaga só o token do aparelho (`204`).
- `POST /api/v1/forgot-password` `{email}` responde a mesma mensagem para e-mails
  cadastrados ou não. O link abre a página de redefinição da web.
- Não há cadastro público. Contas: `php artisan users:create`
  ([operations.md](operations.md)).
- A sessão do navegador não abre a API: o Sanctum aceita apenas tokens
  (`config/sanctum.php`, `guard => []`).

## Fotografia do estado

`GET /api/v1/mobile/bootstrap` → `{state}` com `users`, `clients`, `services`,
`appointments`, `counters` e `meta.serverTime`. O formato segue
`mobile/js/domain.js`:

- IDs numéricos. Datas em milissegundos Unix, convertidas do UTC do banco.
- Perfis `admin`, `team` e `viewer`; o `member` do servidor vira `team`.
- `service.documents` é a lista de nomes. `appointment.documents` traz
  `{id, name, receivedAt}`, e `appointment.activities` traz
  `{id, type, userId, at, old, new}` em ordem cronológica, convertidas do
  histórico persistido.
- Cada atendimento traz também `integrationId`, `formsEnabled`, `formCreateUrl`
  e `forms` (formulários vinculados, links validados por `FormLinks`).

O que não sai do servidor:

- senha, hash, `remember_token` ou tokens;
- o e-mail dos colegas: só o da própria pessoa;
- para visualizadores: CPF/CNPJ e observações de atendidos e atendimentos (vêm
  `null`), e o link de criar formulário. A lista inteira vai para o aparelho; quem
  não edita não precisa desses dados;
- atendimentos removidos logicamente. Atendidos removidos só aparecem quando
  ainda nomeiam um atendimento, marcados como inativos.

Respostas da API levam `Cache-Control: no-store`. Com o volume do projeto, a
fotografia inteira é pequena. Se crescer, o próximo passo é paginar em `store.js`.

## Comandos

`POST /api/v1/mobile/commands` `{name, args}` → `{state, result}`. O `state` é a
fotografia lida depois da gravação; o app só o substitui quando a resposta é de
sucesso.

`name` precisa estar na lista de `MobileCommandRequest::COMMANDS`. Cada nome
corresponde a um método do controller (um `match`), nunca a uma função chamada
pelo texto. O servidor obtém o ator do token, aplica a Policy, valida cada
argumento e chama a Action da web. IDs, perfis e horários enviados pelo app não
são confiáveis: tudo é relido do banco.

| name | args | Action / regra | result |
| --- | --- | --- | --- |
| `saveClient` | `[input, id\|null]` | `SaveClient` (mesmas regras do formulário web) | atendido |
| `scheduleAppointment` | `[input]` | `ScheduleAppointment` (conflito, checklist, histórico) | atendimento |
| `rescheduleAppointment` | `[id, {start, end, responsibleId, updatedAt?}]` | `RescheduleAppointment` | atendimento |
| `changeStatus` | `[id, status, from?]` | `ChangeAppointmentStatus` | atendimento |
| `toggleDocument` | `[appointmentId, documentId, received?]` | `MarkDocumentReceived` | documento |
| `sendReminder` | `[appointmentId]` | `SendAppointmentReminder`, envio imediato | e-mail do atendido |
| `restoreAppointment` | `[{id, status}]` | `UndoStatusChange` | `null` |
| `setServiceActive` | `[slug, active]` | `SaveService` (só admin) | `null` |
| `addServiceDocument` | `[slug, name]` | `SaveService` (só admin, até 30 itens) | `null` |
| `removeServiceDocument` | `[slug, index, name?]` | `SaveService` (só admin) | `null` |

Os argumentos marcados com `?` são opcionais e servem para detectar alterações
concorrentes. O `store.js` os acrescenta sozinho a partir do que a tela mostrava:

- `from`: o status que a pessoa via;
- `updatedAt`: a última alteração do atendimento ou do atendido;
- `received`: o estado desejado do documento, para dois toques não se anularem;
- `name`: o documento que a pessoa quis remover.

`restoreAppointment` não usa a cópia do aparelho. Ele desfaz apenas a última
mudança de status, quando ela é da mesma pessoa, tem menos de 10 minutos e
nada mais mudou depois. Se o horário liberado já foi ocupado, recusa com 409. O
desfazer entra no histórico como uma mudança de status.

## Concorrência

- Agendar trava a linha do responsável. Reagendar, mudar status e desfazer
  travam a linha do atendimento e releem o status salvo. Dois pedidos
  simultâneos esperam um pelo outro.
- A *exclusion constraint* `appointments_no_overlap` continua no banco como
  última barreira. Se ela recusar, a API responde 409.
- `tests/Feature/ConcurrentChangesTest.php` dispara 6 processos ao mesmo tempo,
  cada um com sua conexão: só um agendamento ocupa o horário, e a mesma
  cancelação feita por seis pessoas entra uma vez no histórico. Sem o lock na
  mudança de status, as seis passavam (corrigido também para a web).

## Erros

| HTTP | Quando | Corpo |
| --- | --- | --- |
| 401 | sem token, token expirado ou revogado, conta desativada | `{message}`; o app limpa os dados e volta ao login |
| 403 | perfil sem permissão | `{message: "Seu perfil não tem permissão para fazer isso."}` |
| 404 | registro inexistente ou de outro atendimento | `{message}` sem nome de classe ou tabela |
| 409 | horário ocupado; dado alterado por outra pessoa; desfazer indisponível | `{message, errors}` |
| 422 | validação | `{message, errors: {campo: [mensagem]}}`, com os nomes de campo do app (`start`, `responsibleId`, `tradeName`...) |
| 429 | limite de tentativas | `{message}` e `Retry-After` |
| 503 | o servidor de e-mail não aceitou o lembrete | `{message}`; nada é marcado como enviado |

## Lembretes

- `sendReminder` envia na hora e só responde sucesso depois que o servidor de
  e-mail aceitou a mensagem. Em falha, `reminder_sent_at` volta ao valor anterior.
- Com `MAIL_REMINDERS_ENABLED=false` (padrão de produção até o e-mail ser
  aprovado), a API explica que o envio está desligado e nada é marcado.
- Atalhos `mailto:` e `wa.me` continuam manuais e não chamam o servidor.

## CORS e origens

`CORS_ALLOWED_ORIGINS` lista quem pode chamar a API de outra origem: os apps
nativos (`https://localhost` no Android, `capacitor://localhost` no iOS) e, em
desenvolvimento, o PWA local. O PWA publicado em `https://<domínio>/app/` está no
mesmo endereço da API e não precisa de CORS. As rotas da integração de
formulários ficam fora do CORS, porque são chamadas entre servidores.

## Builds

| Comando (em `mobile/`) | Resultado |
| --- | --- |
| `npm run build:demo` | sempre demo, mesmo com variáveis de servidor (GitHub Pages) |
| `MOBILE_API_URL=https://<domínio>/api/v1 npm run build:server` | modo servidor; falha sem URL HTTPS |
| `MOBILE_API_URL=... npm run sync:server` | build de servidor copiado para `android/` e `ios/` |
| `caderneta-deploy --with-pwa` | build de servidor publicado em `/app/` |

O build de servidor aceita `http://localhost` ou `http://127.0.0.1`, para
testar contra um Laravel na mesma máquina. No modo servidor:

- o login vem vazio e os controles de demonstração somem;
- o service worker não é registrado, então nada fica em cache offline;
- uma falha de rede aparece como erro, sem cair para dados fictícios.

Ao voltar ao app depois de 30 segundos fora, as telas de lista buscam o estado
de novo. Formulários e detalhes mantêm o que está na tela até a pessoa sair
dela. Também dá para atualizar pelo menu do avatar, em "Atualizar dados".

## Validação feita

- `php artisan test --filter=Api`: autenticação, fotografia, comandos, 401, 403,
  404, 409, 422, 429 e 503, escopo do visualizador, auditoria com o usuário do
  token.
- `npm test` em `mobile/`: transporte, store do modo servidor (não usa
  `localStorage`, envia o que a tela mostrava), regras de build.
- De ponta a ponta, no banco que simula o RDS (TLS, `agenda_app`), com o app
  real no navegador:
  - login pela tela e confirmação de um atendimento, gravada com histórico e
    autor;
  - um reagendamento feito pela rota web apareceu no app depois de "Atualizar
    dados".

Testes em aparelhos físicos e a distribuição nas lojas continuam pendentes.

## Diferenças conhecidas entre demo e servidor

- O demo limita um atendimento a 8 horas; o servidor, a 12 (`EnsureResponsibleIsAvailable::MAX_HOURS`).
- O demo zera o lembrete ao reagendar. O servidor mantém `reminder_sent_at`: no
  máximo um lembrete automático por atendimento (RF-073), e a equipe pode
  reenviar na hora.
- No demo, os indicadores do painel de impacto são calculados no aparelho. No
  modo servidor também, a partir da fotografia autorizada; a web ainda não tem
  o painel.
