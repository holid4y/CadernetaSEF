# Contatos e calendários

## Contatos

- “Escrever e-mail” abre o aplicativo do funcionário, com destinatário, assunto e
  mensagem preenchidos. O funcionário escolhe a conta remetente e confirma o envio.
- WhatsApp abre uma conversa com a mensagem pronta. O envio continua manual.

Abrir e-mail ou WhatsApp não altera `reminder_sent_at`. E-mails automáticos são
outra operação, executada pelo servidor e sujeita ao consentimento do atendido.
Atalhos de lembretes respeitam consentimento, conta ativa e perfil com escrita.
Os contatos da ficha permitem uma conversa manual sem presumir que seja lembrete.
A validação confere formato; não verifica se a caixa existe ou se o número possui
conta no WhatsApp. Telefones brasileiros exigem DDD válido e formato de celular
ou fixo; números internacionais exigem `+` e código do país.

## Google Agenda

Não há mais arquivo `.ics` nem link de cópia avulsa: cada pessoa conecta a
própria conta Google, os atendimentos dela aparecem e se atualizam sozinhos numa
agenda “Caderneta SEF”, e os eventos de uma agenda escolhida entram na Caderneta
para serem completados. Configuração, regras e limites:
[google-calendar.md](google-calendar.md).
