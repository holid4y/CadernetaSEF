# Contatos e calendários

## O que funciona sem servidor

- “Escrever e-mail” abre o aplicativo do funcionário, com destinatário, assunto e
  mensagem preenchidos. O funcionário escolhe a conta remetente e confirma o envio.
- WhatsApp abre uma conversa com a mensagem pronta. O envio continua manual.
- “Google Agenda” abre a criação de uma cópia do atendimento na conta escolhida.
- Exportação `.ics` funciona no navegador; no Android/iOS, usa o compartilhamento
  de arquivos do sistema. O aplicativo escolhido precisa aceitar esse formato.

Abrir e-mail ou WhatsApp não altera `reminder_sent_at`. E-mails automáticos são
outra operação, executada pelo servidor e sujeita ao consentimento do atendido.
Atalhos de lembretes respeitam consentimento, conta ativa e perfil com escrita.
Os contatos da ficha permitem uma conversa manual sem presumir que seja lembrete.
A validação confere formato; não verifica se a caixa existe ou se o número possui
conta no WhatsApp. Telefones brasileiros exigem DDD válido e formato de celular
ou fixo; números internacionais exigem `+` e código do país.

## Google Agenda

A cópia por link ou arquivo não é sincronização. Atualizações e cancelamentos na
plataforma não alteram automaticamente uma cópia já adicionada ao Google Agenda.

A agenda da plataforma não importa eventos de outras agendas: atendimentos são
cadastrados nela, que continua sendo a fonte principal. Uma sincronização com a
conta Google dependeria de um projeto Google Cloud, consentimento OAuth, URLs de
retorno registradas e servidor. Tokens do Google teriam de ficar criptografados no
servidor; nunca no GitHub Pages, localStorage, código ou URLs de navegação.
Referência: https://developers.google.com/workspace/calendar/api/auth

O arquivo `.ics` é gerado no próprio navegador ou aparelho, pelo mesmo código na
web e no app (`mobile/js/calendar.js`). Nenhum dado é enviado ao Google durante a
exportação; o link do Google Agenda só abre a tela de criação na conta da pessoa.
