# Contrato da API mobile

O aplicativo não acessa o banco diretamente. O Laravel implementa estes
endpoints sob `MOBILE_API_URL` (por exemplo `https://agenda.example/api/v1`), em
`routes/api.php`. Como cada parte foi implementada, a concorrência e os testes:
[docs/mobile-integration.md](../docs/mobile-integration.md).

A API entre servidores para a plataforma de formulários já existe e usa
autenticação própria. Consulte [o contrato implementado](../docs/forms-integration.md).

## Autenticação

- `POST /login`: `{email, password, device_name}` → `{token, user}`. Token
  pessoal Sanctum; `user` contém `id`, `name`, `email`, `role`, `active`.
- `POST /logout`: revoga o token atual, responde `204`.
- `POST /forgot-password`: `{email}` → `200` com mensagem genérica. Enviar um
  link HTTPS para a página de redefinição de senha do servidor. A redefinição
  acontece nessa página; não depende de deep links para o app.

Todas as rotas abaixo exigem `Authorization: Bearer <token>`.
O token fica somente em memória. Fechar/recarregar o app exige novo login.
Não há cadastro público: contas e perfis são provisionados pelo administrador.

## Consulta inicial e atualização

`GET /mobile/bootstrap` → `{state}`.

`state` contém `users`, `clients`, `services`, `appointments` (arrays),
`counters` e `meta` (objetos). As propriedades dos registros seguem os modelos
em `js/domain.js` e `js/seed.js`; os exemplos do seed são exclusivamente fictícios.
Nunca enviar hashes de senha, tokens de outros usuários ou dados fora do escopo
de acesso do usuário autenticado. O e-mail dos colegas vem `null`, e
visualizadores recebem `document` e `notes` como `null`. O bootstrap usa uma
fotografia completa do escopo autorizado; paginação pode ser adicionada ao
adaptar `store.js` no futuro.

- IDs são números; perfis: `admin`, `team`, `viewer`.
- Datas são números Unix em milissegundos, instantes absolutos. Converta
  os campos UTC do banco no serializador. Não envie strings ISO para as telas
  atuais. O app exibe no fuso do aparelho; use America/Sao_Paulo para operação local.
- `service.documents` é um array de nomes; `appointment.documents` contém
  `{id, name, receivedAt}`. Atendimentos também incluem `activities` e
  `reminderSentAt`; consulte `domain.js` para os demais campos.

## Comandos

`POST /mobile/commands` recebe `{name, args}` e responde `{state, result}`.
`state` é uma fotografia atualizada após a transação, `result` é o registro ou
valor retornado pela operação. Operações sem retorno usam `result: null`.
O cliente só substitui o estado depois de uma resposta de sucesso.

| name | args | result |
| --- | --- | --- |
| `saveClient` | `[input, idOuNull]` | atendido salvo |
| `scheduleAppointment` | `[input]` | atendimento criado |
| `rescheduleAppointment` | `[id, {start, end, responsibleId, updatedAt?}]` | atendimento |
| `changeStatus` | `[id, status, from?]` | atendimento |
| `toggleDocument` | `[appointmentId, documentId, received?]` | documento atualizado |
| `sendReminder` | `[appointmentId]` | e-mail destinatário |
| `restoreAppointment` | `[{id, status}]` | null |
| `setServiceActive` | `[slug, active]` | null |
| `addServiceDocument` | `[slug, name]` | null |
| `removeServiceDocument` | `[slug, index, name?]` | null |

Os argumentos com `?` são opcionais e o `store.js` os preenche com o que a tela
mostrava: `input.updatedAt` também vale para `saveClient`. Quando não batem com o
banco, o servidor responde `409` sem alterar nada.

Use uma lista explícita de comandos permitidos e ações de domínio Laravel.
Nunca execute um nome de função arbitrário recebido do cliente. O servidor
obtém o ator do token e valida perfil, consentimento, status, documentos,
conflitos, duração e todos os campos. IDs e snapshots do cliente não são
confiáveis. `restoreAppointment` deve apenas desfazer uma alteração de status
permitida e ainda atual, usando o histórico persistido; não deve sobrescrever
um atendimento com o snapshot. Rejeite alterações concorrentes com `409`.
Enviar lembrete só deve retornar sucesso quando o envio tiver sido aceito pelo
serviço de e-mail. Regras de referência: `js/domain.js` e testes de domínio.

## Erros e transporte

- `401`: token expirado/revogado ou conta desativada; o app limpa os dados e volta ao login.
- `403`: ação não autorizada.
- `404`: registro inexistente.
- `409`: alteração concorrente ou conflito de horário.
- `422`: `{message, errors: {campo: ["Mensagem em português"]}}`, com os nomes de campo do app.
- `429`: limite de tentativas no login e na recuperação de senha.
- `503`: o servidor de e-mail não aceitou o lembrete.

HTTPS obrigatório nos builds de servidor. CORS deve permitir as origens nativas
`https://localhost` (Android), `capacitor://localhost` (iOS) e a origem web de
produção, com `Authorization`, `Content-Type`, `Accept` e preflight OPTIONS.
Respostas autenticadas devem usar `Cache-Control: no-store`. Nenhum segredo ou
credencial de banco deve ser incluído no app. Não há fila de gravação offline
em modo servidor: falhas são exibidas e o usuário pode tentar novamente.

## Contatos e calendário

Atalhos `mailto:` e `wa.me` são manuais e não chamam `sendReminder`. O funcionário
escolhe a conta de e-mail remetente no aplicativo do aparelho. Só a operação de
envio do servidor pode atualizar `reminder_sent_at`.

A exportação para o Google Agenda e o arquivo `.ics` são gerados no aparelho, sem
chamar a API: [integrações de calendário](../docs/calendar-integrations.md).

## Formulários do parceiro

O serializador de `/mobile/bootstrap` inclui em cada atendimento:

```json
{
  "integrationId": "b30acc5c-b024-4eb0-955a-8e76f9c3a610",
  "formsEnabled": true,
  "formCreateUrl": "https://formularios.exemplo.com/novo?appointment_id=b30acc5c-b024-4eb0-955a-8e76f9c3a610",
  "forms": [
    {
      "title": "Ficha de atendimento",
      "status": "completed",
      "url": "https://formularios.exemplo.com/formularios/123"
    }
  ]
}
```

O identificador é `appointments.integration_id`, nunca o ID numérico ou um hash
de dados pessoais. `forms` vem do relacionamento `externalForms`, só com
registros do atendimento. Os links são gerados por `App\Support\FormLinks`, com
`url: null` nos removidos. `formsEnabled` acompanha `services.forms.enabled`.
Visualizadores recebem `formCreateUrl: null`. A associação manual é feita no web.

No build de servidor, configure `MOBILE_FORMS_ALLOWED_HOSTS` com os mesmos
domínios autorizados no Laravel, separados por vírgula. O app valida os links
novamente. O webhook é chamado exclusivamente pelo servidor parceiro: seu
segredo e a assinatura HMAC nunca são enviados ao aplicativo.
