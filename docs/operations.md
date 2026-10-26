# Operação

O dia a dia do servidor: serviços, deploy, rollback, contas, senhas, filas,
logs, manutenção e resposta a incidentes. Instalação:
[deployment-aws.md](deployment-aws.md). Banco: [database-production.md](database-production.md).

Comandos de banco e de aplicação rodam na EC2, como o usuário `caderneta`.
Para não repetir o caminho:

```sh
cd /var/www/caderneta-sef/current
alias artisan='sudo -u caderneta php /var/www/caderneta-sef/current/artisan'
```

## Serviços

| Serviço | O que faz | Ver estado |
| --- | --- | --- |
| `nginx` | HTTPS, arquivos estáticos, limite de tentativas de login | `systemctl status nginx` |
| `php<versão>-fpm` | executa o Laravel (pool `caderneta-sef`) | `systemctl status 'php*-fpm'` |
| `caderneta-queue.service` | fila: e-mails de lembrete | `systemctl status caderneta-queue` |
| `caderneta-scheduler.timer` | a cada minuto: lembretes (15 min), limpeza de tokens, links de senha e falhas antigas | `systemctl list-timers caderneta-scheduler.timer` |
| `certbot.timer` | renova o certificado HTTPS | `systemctl list-timers certbot.timer` |

`https://<domínio>/up` responde 200 quando a aplicação sobe, sem revelar nada
sobre ela. Use esse endereço em monitoramentos externos.

## Logs

| Onde | O quê | Retenção |
| --- | --- | --- |
| `shared/storage/logs/laravel-AAAA-MM-DD.log` | erros da aplicação (`LOG_LEVEL=warning`) | `LOG_DAILY_DAYS` (30) |
| `journalctl -u caderneta-queue -u caderneta-scheduler` | fila e agendador | journald |
| `/var/log/nginx/caderneta-sef.*.log` | acessos (só caminho, sem query string) e erros | logrotate do Ubuntu |

A aplicação não registra o conteúdo de e-mails, senhas ou respostas da API.
Mesmo assim, logs podem conter nomes em mensagens de erro: não os copie para
fora do servidor sem necessidade. Em produção não use `MAIL_MAILER=log`: ele
grava o texto dos lembretes e os links de redefinição de senha no log.

## Deploy e rollback

```sh
sudo -u caderneta caderneta-deploy --ref main             # só código
sudo -u caderneta caderneta-deploy --ref main --migrate   # depois de um snapshot do RDS
sudo -u caderneta caderneta-rollback                       # volta para a versão anterior
sudo -u caderneta caderneta-rollback 20261023120000        # ou para uma específica
```

Sequência do deploy:

1. confere o `.env` (produção, debug desligado, TLS `verify-full`, `agenda_app`);
2. monta a versão nova em `releases/`;
3. confere a conexão com o banco;
4. para se houver migration pendente sem `--migrate`;
5. com `--migrate`, pede a confirmação `MIGRATE`, coloca o site em manutenção,
   migra como `agenda_owner`, aplica `privileges.sql` e roda `verify.sql`;
6. troca a versão, recarrega o PHP-FPM e reinicia a fila;
7. testa `/up` e, se falhar, volta sozinho para a versão anterior.

O rollback devolve o código, não o banco. As migrations são aditivas, então
o código anterior continua funcionando com o schema novo. Se uma migration
precisar ser desfeita, restaure o snapshot tirado antes do deploy num banco
novo e aponte a aplicação para ele ([database-production.md](database-production.md#restauração)).
Não rode `migrate:rollback` em produção.

Se um deploy parar no meio das migrations, o site fica em manutenção e o script
diz onde. Leia o erro, decida entre corrigir ou restaurar o snapshot, e só então:

```sh
cd /var/www/caderneta-sef/current && sudo -u caderneta php artisan up
```

## Contas

Não há cadastro público nem tela de administração de usuários: as contas são
geridas no servidor.

```sh
artisan users:create pessoa@feevale.br "Nome da Pessoa" --role=member   # admin, member ou viewer
artisan users:deactivate pessoa@feevale.br          # bloqueia, encerra sessões web e tokens do app
artisan users:deactivate pessoa@feevale.br --undo   # devolve o acesso
artisan users:password pessoa@feevale.br            # nova senha; encerra as outras sessões
```

As senhas são digitadas sem eco. Em produção, precisam de 10+ caracteres com
letras e números e não podem aparecer em vazamentos conhecidos (consulta
anônima ao Have I Been Pwned). Contas nunca são apagadas: o histórico continua
apontando para elas.

Para trocar o perfil de alguém:

```sh
artisan tinker --execute="App\Models\User::where('email', 'pessoa@feevale.br')->sole()->update(['role' => 'viewer'])"
```

## Troca de credenciais

| Credencial | Como trocar | Efeito |
| --- | --- | --- |
| Senha de um funcionário | `users:password` | encerra sessões e tokens dele |
| Papéis do banco | `sudo caderneta-database-setup --host <endpoint>`, digitar `ROTATE` | atualiza `.env` e `db-owner.env`, recarrega a aplicação |
| Mestre do RDS | console → *Modify* → *Master password* | nenhum na aplicação |
| Segredo do webhook de formulários | novo valor (`openssl rand -hex 32`) em `FORMS_WEBHOOK_SECRET` nos dois servidores, depois `artisan config:cache` e reload do PHP-FPM | chamadas assinadas com o segredo antigo passam a dar 401 |
| SMTP | `MAIL_*` no `.env`, `config:cache`, reload do PHP-FPM e `systemctl restart caderneta-queue` | nenhum para quem usa o sistema |
| Todos os tokens do app | `artisan tinker --execute="Laravel\Sanctum\PersonalAccessToken::query()->delete()"` | todos fazem login de novo |
| `APP_KEY` | não troque sem o procedimento de [database-production.md](database-production.md#criptografia-na-aplicação-e-a-app_key) | dados criptografados ficariam ilegíveis |

Depois de qualquer mudança no `.env`:

```sh
artisan config:cache
sudo systemctl reload php*-fpm
sudo systemctl restart caderneta-queue
```

## Lembretes e e-mail

Em produção o envio começa desligado (`MAIL_MAILER=array`,
`MAIL_REMINDERS_ENABLED=false`): nada sai e nada é marcado como enviado. Para
ligar:

1. obtenha a aprovação do projeto para escrever aos atendidos e um serviço de
   e-mail. O gratuito mais simples é uma conta Gmail do projeto com verificação
   em 2 etapas e uma "senha de app" (Conta Google → Segurança → Senhas de app),
   que envia cerca de 500 mensagens por dia para qualquer provedor;
2. rode `sudo caderneta-mail-setup`. Ele pede o endereço, o servidor SMTP
   (padrão Gmail) e a senha sem mostrá-la, grava em `MAIL_*` no `.env`,
   recarrega a aplicação e manda um e-mail de teste. A partir daí, a
   recuperação de senha da equipe já funciona;
3. teste com `MAIL_ALWAYS_TO=caixa-de-teste@...`: toda mensagem vai para essa
   caixa. Peça uma redefinição de senha e envie um lembrete de um atendimento
   de teste;
4. confirmado o recebimento, esvazie `MAIL_ALWAYS_TO` e ligue
   `MAIL_REMINDERS_ENABLED=true`.

Os lembretes automáticos saem uma vez por atendimento, cerca de 24 horas antes,
só para atendidos com consentimento e e-mail válido. O job confere de novo ao
rodar, então um atendimento cancelado ou um consentimento retirado entre o
agendamento e o envio não recebe e-mail. Falhas tentam 3 vezes (1 e 5 minutos
de intervalo) e depois ficam em `failed_jobs`:

```sh
artisan queue:failed
artisan queue:retry all     # depois de corrigir a causa
artisan queue:forget <id>   # descartar
```

## Conferir que web e app usam os dados reais

1. Crie um atendimento de teste na web, para um atendido de teste.
2. No app (`https://<domínio>/app/` ou o build nativo), abra o menu do avatar →
   "Atualizar dados": o atendimento aparece.
3. Mude o status no app; recarregue a página do atendimento na web: o status e
   o histórico mostram a mudança e quem a fez.
4. No banco, sem ver dados pessoais:
   ```sh
   sudo -u caderneta caderneta-check-db
   ```
   Para contagens, use o papel de relatórios e a view `appointment_facts`.
5. Cancele o atendimento de teste: atendimentos não são apagados.

## Manutenção

- Sistema operacional. `unattended-upgrades` aplica atualizações de segurança.
  Reinicie em horário combinado quando `/var/run/reboot-required` existir; os
  serviços voltam sozinhos.
- Nova versão do PHP (upgrade do Ubuntu). O pool e o `.ini` ficam na pasta
  da versão: rode `sudo deploy/aws/bin/provision.sh <domínio>` de novo
  e um deploy.
- RDS. As atualizações menores e a janela de manutenção ficam no console.
  O banco fica indisponível por alguns minutos; avise a equipe.
- Certificado. `sudo certbot renew --dry-run` confere a renovação.
- Disco. `df -h`; versões antigas são limpas a cada deploy (ficam 5), e os
  dumps em `/var/backups/caderneta-sef` duram 14 dias.
- Modo manutenção. `artisan down --retry=60` e `artisan up`.

## Incidentes

| Sintoma | Primeiro passo |
| --- | --- |
| site fora do ar | `systemctl status nginx 'php*-fpm'`; `/up`; log do dia em `storage/logs` |
| erro 500 em todas as páginas | log do Laravel; se começou num deploy, `caderneta-rollback` |
| "Não foi possível conectar ao servidor" no app | o app depende do HTTPS e de `/api/v1`; confira `/up` e o certificado |
| banco inacessível | `caderneta-check-db` indica a etapa que falha |
| lembretes não saem | `MAIL_REMINDERS_ENABLED`, `systemctl status caderneta-queue`, `artisan queue:failed`, `systemctl list-timers` |
| `429` frequente no login | muitas pessoas na mesma rede; o limite é por IP (20/min) e por conta (5 erros) |
| suspeita de senha ou token vazado | `users:password` da conta; se for o banco, `caderneta-database-setup` (ROTATE); se for geral, apague todos os tokens |
| dado apagado ou alterado por engano | atendimentos e histórico não podem ser apagados. Para o resto, restaure um ponto anterior num banco novo e copie o registro |

Durante um incidente, registre o horário, o que foi visto e o que foi feito.
Se dados pessoais tiverem sido expostos, avise a coordenação do projeto e o
encarregado de dados da universidade: a LGPD prevê comunicação à ANPD e aos
titulares em incidentes relevantes.
