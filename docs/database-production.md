# Banco de dados em produção (Amazon RDS)

O PostgreSQL do RDS é a única fonte dos dados: web e app gravam nele pelas mesmas
regras do Laravel. Este documento cobre papéis, migrations, TLS, backups e
restauração. A instalação da EC2 está em [deployment-aws.md](deployment-aws.md).

## Valores reais

| Item | Valor |
| --- | --- |
| Instância | `caderneta-sef-db` (`sa-east-1`, db.t4g.micro, Single-AZ) |
| Banco | `caderneta_sef` (não `agenda_contabil`) |
| Usuário mestre | `postgres`: só administração, nunca usado pela aplicação |
| Porta | 5432 |
| Acesso público | Não. Só o security group da EC2 alcança o banco |
| CA | `rds-ca-rsa2048-g1`, no pacote `global-bundle.pem` |

O endpoint fica apenas no `.env` do servidor, nunca no Git.

## Papéis

| Papel | Uso | Pode |
| --- | --- | --- |
| `postgres` (mestre do RDS) | `roles.sql`, criar bancos de restauração | criar papéis; não é superusuário |
| `agenda_owner` | dono das tabelas; migrations; backups | DDL; mesmo assim é barrado pelos triggers de histórico |
| `agenda_app` | a aplicação (`DB_USERNAME`) | ler e gravar dados; não cria tabelas, não apaga atendimentos, atendidos, usuários, serviços nem documentos, não altera histórico, auditoria ou recibos de webhook |
| `agenda_reports` | ferramentas de relatório | só `SELECT` na view `appointment_facts`, sem nome, contato, documento ou observações |

Arquivos em [`database/sql/`](../database/sql):

- `roles.sql`, rodado pelo mestre: cria ou troca a senha dos três papéis e
  transfere o banco para `agenda_owner`. As senhas são lidas de variáveis de
  ambiente, então não aparecem no histórico do shell nem na lista de processos;
- `privileges.sql`, rodado pelo dono depois de toda migration: concede o
  mínimo e define os privilégios padrão das tabelas futuras;
- `verify.sql`, que qualquer papel roda e que não altera nada: confere
  permissões, triggers, a exclusion constraint, `btree_gist` e se a conexão está
  criptografada. Para com a lista do que estiver errado.

`caderneta-database-setup` e `caderneta-deploy --migrate` executam os três na
ordem certa.

### Por que funciona no RDS

O usuário mestre do RDS não é superusuário. Por isso:

- `roles.sql` não declara atributos que só um superusuário pode mencionar
  (`NOSUPERUSER`, `NOREPLICATION`, `NOBYPASSRLS`). Papéis novos já nascem sem
  eles, e o `verify.sql` confere;
- a partir do PostgreSQL 16, só quem pode agir como um papel transfere objetos
  para ele: o script dá ao mestre `SET` sobre `agenda_owner` antes do
  `ALTER DATABASE ... OWNER`;
- `btree_gist` é uma extensão *trusted* (PostgreSQL 13+): o dono do banco a cria
  na migration sem superusuário.

Validado em 22/10/2026 num PostgreSQL 18.6 local montado como o RDS: mestre sem
superusuário, só conexões TLS (sem TLS: recusada) e certificado conferido com
`verify-full` (nome errado: recusado). Resultado:

- `roles.sql` rodou duas vezes sem erro;
- as 12 migrations rodaram como `agenda_owner`, e `migrate:fresh` foi bloqueado
  em produção;
- `privileges.sql` e `verify.sql` passaram;
- como `agenda_app`, `CREATE TABLE`, `DELETE` em atendimentos e usuários, `UPDATE`
  no histórico, `TRUNCATE` na auditoria, `DROP` e `DISABLE TRIGGER` foram negados,
  e o `agenda_reports` só leu a view;
- a aplicação inteira (web, API do app, fila, agendador e limpezas) rodou como
  `agenda_app` sobre TLS.

## Primeira configuração

Na EC2:

```sh
sudo caderneta-database-setup --host <endpoint do RDS>
```

Equivale a, manualmente:

```sh
export AGENDA_OWNER_PASSWORD=... AGENDA_APP_PASSWORD=... AGENDA_REPORTS_PASSWORD=...   # 16+ caracteres
psql "host=<endpoint> dbname=caderneta_sef user=postgres sslmode=verify-full sslrootcert=/etc/ssl/certs/rds-global-bundle.pem" \
     -f database/sql/roles.sql
```

Prefira senhas só com letras e números (o script gera assim). Um `#` no `.env`
começa um comentário se o valor não estiver entre aspas, e em URLs vira `%23`.

## Migrations

- Rodam somente com `caderneta-deploy --migrate`, depois de um snapshot manual e
  da confirmação digitada `MIGRATE`, com o usuário `agenda_owner`.
- `migrate:fresh`, `db:wipe` e afins são bloqueados com `APP_ENV=production`
  (`DB::prohibitDestructiveCommands`). Não rode `DROP`, `TRUNCATE` ou `DELETE`
  manual em produção.
- Escreva migrations aditivas: coluna nova anulável, preenchimento em lotes,
  restrição depois. O código antigo continua funcionando enquanto o novo sobe.
- Tabela nova que precise ser só de inserção recebe seu `REVOKE` em
  `privileges.sql` e uma linha no `verify.sql`.
- O `down()` das migrations existe para desenvolvimento. Em produção, desfazer
  uma migration é restaurar o snapshot (abaixo), nunca `migrate:rollback`
  sobre dados reais.

## TLS

O Laravel conecta com `DB_SSLMODE=verify-full` e
`DB_SSLROOTCERT=/etc/ssl/certs/rds-global-bundle.pem`. Assim, a conexão é
criptografada e o certificado precisa ser do RDS, emitido para aquele endpoint.
O pacote global contém todas as CAs do RDS, inclusive a `rds-ca-rsa2048-g1`.

O certificado do servidor vence em 22/10/2027. Com essa CA, o RDS o renova
sozinho, sem trocar o pacote. Se um dia a CA da instância mudar, rode
`provision.sh` de novo para baixar o pacote atualizado.

## Criptografia na aplicação e a APP_KEY

CPF/CNPJ, observações, descrição da demanda e links de formulários são gravados
criptografados com a `APP_KEY`. A busca por CPF/CNPJ usa um HMAC do número feito
com a mesma chave (`App\Support\BlindIndex`). Consequências:

- sem a `APP_KEY`, um backup restaurado perde esses campos. Guarde a chave fora do
  servidor, no cofre de senhas do projeto;
- não troque a `APP_KEY` de um banco com dados. `APP_PREVIOUS_KEYS` só ajuda a
  ler o que foi criptografado com a chave antiga; o índice de CPF/CNPJ
  continuaria calculado com ela. Uma troca exige recriptografar e recalcular o
  índice num procedimento próprio, testado numa cópia.

## Backups

1. Backups automáticos do RDS, a base de tudo. Em *Maintenance & backups*,
   mantenha a retenção em 7 dias ou mais: permitem restaurar qualquer momento do
   período (point-in-time).
2. Snapshot manual antes de cada deploy com migrations: RDS →
   `caderneta-sef-db` → *Actions* → *Take snapshot*. Fica guardado até ser
   apagado.
3. Dump lógico com `sudo -u caderneta caderneta-backup`, em
   `/var/backups/caderneta-sef` (permissão 600, 14 dias). Serve para conferir
   dados ou levar uma cópia para outro lugar com autorização. Ele contém dados
   pessoais: não copie para máquinas pessoais nem para o Git.

## Restauração

A restauração nunca vai por cima do banco em uso: cria-se outro, confere-se e só
então a aplicação é apontada para ele.

### A partir do RDS (recomendado)

1. RDS → *Automated backups* → *Restore to point in time*, ou a partir de um
   snapshot, com um novo identificador (ex.: `caderneta-sef-db-restore`), a
   mesma VPC, o security group `rds-ec2-1` e *Publicly accessible = No*.
2. Na EC2, confira o novo endpoint:
   ```sh
   sudo -u caderneta env CADERNETA_ENV_FILE=/tmp/restore.env caderneta-check-db
   ```
   Use uma cópia do `.env` com o `DB_HOST` novo; apague-a depois.
3. Com o site em manutenção (`php artisan down`), troque o `DB_HOST` no `.env`,
   rode `php artisan config:cache`, recarregue o PHP-FPM, reinicie a fila e
   tire a manutenção.
4. Depois de dias de funcionamento, apague a instância antiga (antes, um
   snapshot final). Uma instância a mais custa enquanto existir.

### A partir de um dump lógico

```sh
# como postgres (mestre), um banco vazio do dono
psql "... dbname=postgres user=postgres" -c "CREATE DATABASE caderneta_sef_restore OWNER agenda_owner" \
     -c "REVOKE ALL ON DATABASE caderneta_sef_restore FROM PUBLIC" \
     -c "GRANT CONNECT ON DATABASE caderneta_sef_restore TO agenda_app, agenda_reports"
# como agenda_owner
pg_restore --dbname="host=... dbname=caderneta_sef_restore user=agenda_owner sslmode=verify-full sslrootcert=..." \
     --no-owner --no-privileges --exit-on-error /var/backups/caderneta-sef/<arquivo>.dump
psql "... dbname=caderneta_sef_restore user=agenda_owner" -f database/sql/privileges.sql
psql "... dbname=caderneta_sef_restore user=agenda_app" -f database/sql/verify.sql
```

Depois, troque `DB_DATABASE` no `.env` como no passo 3 acima. Esse caminho foi
ensaiado no banco simulado: as contagens de atendimentos, histórico, auditoria e
serviços ficaram iguais e o `verify.sql` passou no banco restaurado.

## Diagnóstico de conexão

`sudo -u caderneta caderneta-check-db` testa, em ordem e só lendo:

1. o pacote de certificados;
2. se o endpoint resolve para IP privado;
3. se a porta 5432 responde (*security groups*);
4. login com TLS `verify-full`;
5. o `verify.sql`.

Cada falha vem com o que conferir:

| Sintoma | Causa provável |
| --- | --- |
| porta não responde | regra 5432 do `rds-ec2-1` não aponta para o SG da EC2, ou VPCs diferentes |
| `certificate verify failed` | endpoint digitado errado ou pacote de certificados ausente |
| `password authentication failed` | senha do `.env` diferente da do papel: rode `caderneta-database-setup` (ROTATE) |
| `no pg_hba.conf entry ... no encryption` | conexão sem TLS; confira `DB_SSLMODE` |
| `permission denied` na aplicação | migration nova sem `privileges.sql`; rode o deploy com `--migrate` ou o script manualmente como dono |

## Troca de senhas

- Papéis da aplicação: `sudo caderneta-database-setup --host <endpoint>` e
  digite `ROTATE`. Gera senhas novas, atualiza o `.env` e o `db-owner.env`, e
  recarrega a aplicação.
- Usuário mestre: console do RDS → *Modify* → *Master password*. Troque-a
  depois da configuração inicial, principalmente se ela foi compartilhada.
