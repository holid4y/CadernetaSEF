# Integração com a plataforma de formulários

O Laravel recebe metadados dos formulários do outro grupo, os vincula aos
atendimentos e entrega à plataforma deles os dados de cada atendimento, para os
formulários já virem preenchidos. O conteúdo das respostas continua na
plataforma de formulários. Cada atendimento, na web e no app, tem um botão para
o formulário: “Preencher formulário” enquanto ele está por fazer (ou ainda não
existe) e “Ver formulário feito” depois de concluído. Cada plataforma mantém seu
próprio login e suas permissões.

Esta API está implementada, assim como a [API do aplicativo](mobile-integration.md),
que é separada e usa tokens de funcionários. A integração real com o outro grupo
depende da adoção deste contrato e da configuração dos servidores.

## Identificação do atendimento

Cada atendimento tem um UUID persistido em `appointments.integration_id`. A
migration preenche também os registros existentes, inclusive os removidos
logicamente. O identificador não depende de nome, CPF, horário ou das respostas.

O botão “Preencher formulário” de um atendimento sem formulário acrescenta
`appointment_id=<UUID>` à URL configurada.
A plataforma parceira deve guardar essa referência e devolvê-la nas notificações.
O funcionário também pode copiar a referência nos detalhes do atendimento.
O UUID identifica o atendimento; ele não é uma credencial de acesso.

Se o formulário chegar sem referência ou com referência desconhecida, fica em
`/integracoes/formularios`. Um funcionário com permissão de escrita abre o
atendimento desejado, escolhe “Revisar vínculos pendentes” e confirma a associação.
Nenhum atendimento é criado automaticamente. Um vínculo existente não pode ser
transferido pelo webhook nem pela tela de revisão.

## Configuração

No `.env` do servidor da agenda:

```dotenv
FORMS_ENABLED=true
FORMS_WEBHOOK_SECRET=
FORMS_ALLOWED_HOSTS=formularios.exemplo.com
FORMS_CREATE_URL=https://formularios.exemplo.com/formularios/novo
```

Gere um segredo exclusivo com `openssl rand -hex 32`, coloque o resultado em
`FORMS_WEBHOOK_SECRET` nos dois servidores e compartilhe-o por um canal privado.
Ele nunca entra no Git, no app ou no JavaScript do site. O segredo precisa ter
pelo menos 32 caracteres. A integração vem desativada por padrão.

`FORMS_ALLOWED_HOSTS` aceita nomes exatos separados por vírgula, sem protocolo,
porta, caminho ou wildcard. Só links HTTPS nesses domínios são aceitos. URLs com
credenciais ou porta diferente de 443 são recusadas. `FORMS_CREATE_URL` é opcional;
sem ela, os formulários recebidos continuam podendo ser abertos.

Em produção, as migrations rodam com `caderneta-deploy --migrate`, como o dono do
banco, seguidas de `database/sql/privileges.sql`. Os recibos de
`form_webhook_events` ficam só de inserção para a aplicação. Depois de mudar o
`.env`, rode `php artisan config:cache` e recarregue o PHP-FPM
([operations.md](operations.md)). O Nginx entrega HTTPS direto ao PHP-FPM; se
um balanceador ou CDN for posto na frente, configure os proxies confiáveis no
Laravel.

Para desenvolvimento, o mesmo backend pode rodar sem hospedagem externa:

```sh
php artisan serve --host=127.0.0.1 --port=8097
```

As rotas ficam sob `http://127.0.0.1:8097/api/v1/integrations/forms`. HTTPS não
é exigido no ambiente `local`; a assinatura continua obrigatória. O domínio
`forms.example.test` pode ser usado apenas como placeholder para testar a
validação de links, sem uma plataforma parceira conectada. Não use o banco de
testes para desenvolver nem publique o segredo do seu `.env`.

## Assinatura

As duas rotas abaixo usam HMAC-SHA256, sem cookie de sessão nem token de usuário:

```text
X-Forms-Timestamp: <Unix em segundos>
X-Forms-Signature: sha256=<HMAC em hexadecimal minúsculo>
```

A mensagem assinada é a concatenação exata, com LF entre as partes e sem LF
extra no fim:

```text
timestamp + "\n" + MÉTODO + "\n" + caminho_e_query + "\n" + corpo_bruto
```

Use o método em maiúsculas e o caminho começando por `/api/v1/`, incluindo a query
na ordem enviada, caso exista. Assine os mesmos bytes de JSON que serão enviados.
No GET, o corpo é vazio. São aceitos até cinco minutos de diferença de relógio.
Cada nova tentativa usa timestamp e assinatura novos, preservando o evento.
Há limite de 60 requisições por minuto por IP e corpo máximo de 64 KiB.

## Notificações

`POST /api/v1/integrations/forms/events`, com `Content-Type: application/json`:

```json
{
  "event_id": "25929d0c-f853-439a-a14f-6d38634bd697",
  "form_id": "formulario-123",
  "appointment_id": "b30acc5c-b024-4eb0-955a-8e76f9c3a610",
  "version": 1,
  "status": "pending",
  "title": "Ficha de atendimento",
  "url": "https://formularios.exemplo.com/formularios/123",
  "occurred_at": "2026-10-22T15:00:00Z"
}
```

`event_id` é um UUID exclusivo de cada notificação. `form_id` é estável, tem até
128 caracteres e aceita letras ASCII, números, ponto, hífen, dois-pontos e
underscore, começando por letra ou número. Há uma plataforma parceira configurada;
os IDs precisam ser únicos nela. `appointment_id` pode ser omitido ou nulo.
`version` é um inteiro de 1 a 2147483647, crescente por formulário, definido pela
plataforma parceira. `title` tem até 160 caracteres; prefira títulos genéricos.
`occurred_at` deve ser ISO 8601 com fuso explícito; frações de segundo são aceitas.

Estados: `pending`, `in_progress`, `completed` e `deleted`. A remoção mantém um
registro sem link; nesse caso `url` pode ser omitida ou nula. Para restaurar um
formulário, envie um estado ativo com versão maior e um link válido. Não envie
respostas, anexos, CPF, e-mail ou outros campos: campos fora do contrato são
recusados. As URLs ficam criptografadas no banco.

| HTTP | `result` ou significado |
| --- | --- |
| 200 | `linked`: formulário vinculado ao atendimento |
| 202 | `pending_review`: recebido e aguardando vínculo; não exige reenvio |
| 200 | `duplicate`: o mesmo evento já foi processado |
| 200 | `ignored_old_version`: versão inferior ou versão igual com os mesmos dados |
| 400 | HTTPS obrigatório em produção |
| 401 | assinatura inválida ou relógio fora da janela |
| 409 | event_id reutilizado com outros dados, versão igual divergente ou tentativa de trocar o vínculo |
| 413 / 415 | corpo muito grande ou formato diferente de JSON |
| 422 | campos inválidos ou link fora dos domínios permitidos |
| 429 / 503 | limite de requisições ou integração indisponível |

Eventos e atualizações são gravados na mesma transação. Índices únicos impedem
duplicação de eventos e formulários; atualizações bloqueiam o registro durante a
gravação. Não apague os recibos de `form_webhook_events` enquanto precisar da
garantia de idempotência. Eles guardam hashes e resultado, sem copiar o corpo.
Eventos antigos não sobrescrevem dados novos. Datas servem para exibição; a
ordem das alterações é determinada por `version`.

Retente erros de rede, 429 e 5xx com intervalo crescente. Preserve `event_id`,
versão e conteúdo ao repetir uma notificação. Corrija erros 4xx antes de tentar
novamente; uma mudança de conteúdo exige um evento novo e, quando aplicável,
versão maior. Reenvio sem `appointment_id` preserva um vínculo já existente.

## Consulta pelo parceiro

As consultas usam os mesmos cabeçalhos de assinatura e corpo vazio.

`GET /api/v1/integrations/forms/appointments/{UUID}` devolve o atendimento:

```json
{
  "appointment_id": "b30acc5c-b024-4eb0-955a-8e76f9c3a610",
  "status": "scheduled",
  "status_label": "Agendado",
  "starts_at": "2026-10-22T12:00:00+00:00",
  "ends_at": "2026-10-22T13:00:00+00:00",
  "location_type": "on_campus",
  "location": "Sala 205",
  "service": { "slug": "irpf", "name": "IRPF", "category": "Fiscal", "details": null },
  "responsible": { "name": "Ana Souza" },
  "client": {
    "type": "individual",
    "name": "Joana Lima",
    "trade_name": null,
    "document": "529.982.247-25",
    "email": "joana@exemplo.com",
    "phone": "(51) 99125-1538"
  },
  "documents": [{ "name": "Informe de rendimentos", "received": false }],
  "forms": [{ "form_id": "formulario-123", "title": "Ficha de atendimento", "status": "pending", "url": "https://formularios.exemplo.com/formularios/123" }],
  "created_at": "2026-10-20T14:03:11+00:00",
  "updated_at": "2026-10-21T09:15:40+00:00"
}
```

`GET /api/v1/integrations/forms/appointments?from=2026-10-01&to=2026-10-31`
lista os atendimentos que acontecem entre os dois dias (horário de São Paulo,
incluindo os dois), no mesmo formato, 50 por página, em ordem de início:
`{"data": [...], "next_page": 2}`. Peça a página seguinte com `&page=2` até
`next_page` vir `null`. O período vai até 92 dias. Assine o caminho com a query
exatamente como enviada.

Referências inexistentes ou atendimentos removidos logicamente respondem 404.
As observações internas da equipe e o histórico não saem da Caderneta.
CPF/CNPJ, e-mail e telefone saem, porque os formulários precisam deles: a
plataforma parceira passa a tratar esses dados pessoais e deve guardá-los com o
mesmo cuidado (LGPD), sem expor a outros sistemas.

## Exemplo de envio pelo servidor parceiro

Em Node 22+, configure `AGENDA_URL`, sem caminho, e `AGENDA_WEBHOOK_SECRET` no
servidor parceiro. O exemplo usa o mesmo objeto de metadados do contrato:

```js
import { createHmac } from 'node:crypto';

const path = '/api/v1/integrations/forms/events';
const body = JSON.stringify(event);
const timestamp = String(Math.floor(Date.now() / 1000));
const signature = createHmac('sha256', process.env.AGENDA_WEBHOOK_SECRET)
    .update(`${timestamp}\nPOST\n${path}\n${body}`)
    .digest('hex');
const response = await fetch(new URL(path, process.env.AGENDA_URL), {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Forms-Timestamp': timestamp,
        'X-Forms-Signature': `sha256=${signature}`,
    },
    body,
    redirect: 'error',
    signal: AbortSignal.timeout(20000),
});
if (!response.ok) throw new Error(`Agenda respondeu ${response.status}`);
```

O Pages permanece somente uma demonstração. A exportação web recusa bancos com
metadados de formulários externos e desativa os links da integração. Builds
mobile `--demo` descartam os domínios configurados para o parceiro.
